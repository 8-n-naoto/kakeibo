<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ImportIgnoreRule;
use App\Models\ImportProfile;
use App\Models\Transaction;
use App\Services\CsvImportService;
use App\Support\DomainLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * クレジットカード明細などのCSVを取り込む。
 * アップロード → 解析結果のプレビュー(セッション保持) → 確定登録 の3ステップ。
 *
 * ■ 確定登録で守っていること
 *   - **送られてきた行数が解析時と違えば登録しない。**
 *     PHP の max_input_vars（既定1000）を超えるとPOSTは黙って切り捨てられる。
 *     1行あたり6個の入力があるので、166行を超えると後半が消え、
 *     「200件のつもりが166件しか入っていない」ことに誰も気づけない。
 *   - **同じプレビューからの二度目の送信は受け付けない。**
 *     ブラウザバック＋再送信で全件が二重に入るのを防ぐ。
 *   - **登録の直前にもう一度、重複を確認する。**
 *     プレビューを開いている間にレシートから登録した分を二重計上しない。
 */
class TransactionImportController extends Controller
{
    private const SESSION_KEY = 'csv_import_rows';

    private const SESSION_TOKEN = 'csv_import_token';

    private const SESSION_FILE = 'csv_import_file';

    /** 列の対応をやり直すために、アップロードされたCSVそのものを取っておく場所 */
    private const SESSION_SOURCE = 'csv_import_source';

    private const SESSION_META = 'csv_import_meta';

    /** 取っておいたCSVの置き場（非公開ディスク） */
    private const SOURCE_DIRECTORY = 'imports';

    /**
     * 既定ディスクではなく local を名指しする。
     * FILESYSTEM_DISK を public にされると明細CSVが公開ディレクトリに置かれ、
     * s3 にされると path() が使えず取り込みが丸ごと動かなくなる。
     */
    private function sources(): Filesystem
    {
        return Storage::disk('local');
    }

    public function create()
    {
        return view('imports.create', [
            'ignoreRules' => ImportIgnoreRule::query()->orderBy('display_name')->get(),
        ]);
    }

    /** 「今後取り込まない」ルールを消す */
    public function destroyIgnoreRule(ImportIgnoreRule $ignoreRule)
    {
        $name = $ignoreRule->display_name ?? $ignoreRule->pattern;
        $ignoreRule->delete();

        return redirect()->route('imports.create')
            ->with('status', sprintf('「%s」を取り込まないルールを解除しました。', $name));
    }

    public function store(Request $request, CsvImportService $service)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,tsv'],
        ]);

        $this->pruneAbandonedSources();

        // 列の対応をやり直すときに再アップロードさせないよう、CSVを取っておく。
        // **前の取り込みのぶんは、今回の解析が通ってから消す。**
        // 先に消すと、新しいファイルが壊れていたときに前の取り込みだけが
        // 「プレビューは開けるのに列を直せない」半端な状態で残る。
        $stored = $request->file('file')->store(self::SOURCE_DIRECTORY, 'local');

        if ($stored === false) {
            return back()->withErrors(['file' => 'CSVを保存できませんでした。ディスクの空きを確認してください。']);
        }

        try {
            // まず自動判定。同じ並びのCSVを前に手で直していれば、その対応を使う
            $parsed = $service->parse($this->sources()->path($stored));

            // どちらも sanitize を通しているのでキーの順序が揃う（=== で比べられる）
            $saved = $parsed['signature'] === null
                ? null
                : ImportProfile::mappingFor($parsed['signature']);

            if ($saved !== null && $saved !== ImportProfile::sanitize($parsed['detected'])) {
                $parsed = $service->parse($this->sources()->path($stored), $saved);
                $parsed['profile_applied'] = true;
            }
        } catch (Throwable $e) {
            $this->sources()->delete($stored);

            return back()->withErrors(['file' => 'CSVの解析に失敗しました: '.$e->getMessage()]);
        }

        if ($parsed['rows'] === []) {
            $this->sources()->delete($stored);

            return back()->withErrors(['file' => '取り込める行が見つかりませんでした。']);
        }

        $this->forgetSource($request);
        $this->rememberParsed($request, $parsed, $stored, $request->file('file')->getClientOriginalName());

        return redirect()->route('imports.preview');
    }

    /**
     * 列の対応を手で指定し直して、同じCSVを読み直す。
     */
    public function remap(Request $request, CsvImportService $service)
    {
        $source = $request->session()->get(self::SESSION_SOURCE);

        if (! is_string($source) || ! $this->sources()->exists($source)) {
            return redirect()->route('imports.create')
                ->withErrors(['file' => '元のCSVが見つかりませんでした。もう一度アップロードしてください。']);
        }

        $validated = $request->validate([
            'mapping' => ['required', 'array', 'max:20'],
            'mapping.header_row' => ['nullable', 'integer', 'min:0', 'max:999'],
            'mapping.date' => ['nullable', 'integer', 'min:0', 'max:999'],
            'mapping.shop' => ['nullable', 'integer', 'min:0', 'max:999'],
            'mapping.amount' => ['nullable', 'integer', 'min:0', 'max:999'],
            'mapping.income' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $mapping = ImportProfile::sanitize($validated['mapping']);

        if ($mapping['date'] === null || ($mapping['amount'] === null && $mapping['income'] === null)) {
            return back()->withErrors(['mapping' => '日付の列と、金額（出金か入金）の列は指定してください。']);
        }

        try {
            $parsed = $service->parse($this->sources()->path($source), $mapping);
        } catch (Throwable $e) {
            return back()->withErrors(['mapping' => 'この指定では読み取れませんでした: '.$e->getMessage()]);
        }

        if ($parsed['rows'] === []) {
            // ヘッダー行を最終行にした場合など。ここで戻さないと
            // プレビューが空になり「セッションが切れた」ように見えてしまう
            return back()->withErrors([
                'mapping' => 'その指定ではデータ行が1件も残りません。ヘッダー行の指定を確認してください。',
            ]);
        }

        $meta = (array) $request->session()->get(self::SESSION_META, []);

        // 手で直したときだけ覚える（自動判定のぶんまで覚えると、
        // まったく別のCSVに前回の対応を当ててしまう）
        $parsed['manual'] = true;

        $this->rememberParsed($request, $parsed, $source, $meta['file_name'] ?? null);

        return redirect()->route('imports.preview')
            ->with('status', '指定した列で読み直しました。');
    }

    /**
     * 解析結果と、やり直しに必要な材料をセッションに置く。
     *
     * @param  array<string, mixed>  $parsed
     */
    private function rememberParsed(Request $request, array $parsed, string $source, ?string $fileName): void
    {
        $request->session()->put(self::SESSION_KEY, $parsed['rows']);
        $request->session()->put(self::SESSION_TOKEN, (string) Str::uuid());
        $request->session()->put(self::SESSION_FILE, $fileName);
        $request->session()->put(self::SESSION_SOURCE, $source);
        $request->session()->put(self::SESSION_META, [
            'file_name' => $fileName,
            'columns' => $parsed['columns'] ?? [],
            'detected' => ImportProfile::sanitize($parsed['detected'] ?? []),
            'signature' => $parsed['signature'] ?? null,
            'row_labels' => $parsed['row_labels'] ?? [],
            'profile_applied' => (bool) ($parsed['profile_applied'] ?? false),
            'manual' => (bool) ($parsed['manual'] ?? false),
        ]);
    }

    /**
     * 取り込みを途中でやめたぶんのCSVを片付ける。
     * セッションが切れると誰も消せなくなるので、1日置いたものは消す。
     */
    private function pruneAbandonedSources(): void
    {
        $threshold = Carbon::now()->subDay()->getTimestamp();

        foreach ($this->sources()->files(self::SOURCE_DIRECTORY) as $file) {
            try {
                if ($this->sources()->lastModified($file) < $threshold) {
                    $this->sources()->delete($file);
                }
            } catch (Throwable) {
                // 消せなくても取り込みは続ける
            }
        }
    }

    /**
     * 「取り込まない」ルールを覚えたあと、セッションに持っている行に印を付け直す。
     */
    private function refreshIgnoreMarks(Request $request): void
    {
        $rules = ImportIgnoreRule::query()->get();

        $rows = collect($request->session()->get(self::SESSION_KEY, []))
            ->map(function ($row) use ($rules) {
                if (! is_array($row) || empty($row['importable'])) {
                    return $row;
                }

                $row['ignored_by'] = ImportIgnoreRule::matchIn($rules, $row['shop_name'] ?? null);

                return $row;
            })
            ->all();

        $request->session()->put(self::SESSION_KEY, $rows);
    }

    private function forgetSource(Request $request): void
    {
        $source = $request->session()->get(self::SESSION_SOURCE);

        if (is_string($source) && $source !== '') {
            $this->sources()->delete($source);
        }

        $request->session()->forget(self::SESSION_SOURCE);
    }

    public function preview(Request $request)
    {
        $rows = $request->session()->get(self::SESSION_KEY);

        if (empty($rows)) {
            return redirect()->route('imports.create')
                ->withErrors(['file' => '取り込み対象のデータがありません。もう一度CSVをアップロードしてください。']);
        }

        $collection = collect($rows);
        $importable = $collection->where('importable', true);

        $meta = (array) $request->session()->get(self::SESSION_META, []);

        return view('imports.preview', [
            'rows' => $rows,
            'token' => $request->session()->get(self::SESSION_TOKEN, ''),
            'columns' => $meta['columns'] ?? [],
            'rowLabels' => $meta['row_labels'] ?? [],
            'mapping' => $meta['detected'] ?? [],
            'profileApplied' => (bool) ($meta['profile_applied'] ?? false),
            'fileName' => $meta['file_name'] ?? null,
            'categories' => Category::orderBy('type')->orderBy('sort_order')->get(),
            'importableCount' => $importable->where('duplicate', false)
                ->whereNull('receipt_match_of')
                ->whereNull('ignored_by')
                ->count(),
            'duplicateCount' => $collection->where('duplicate', true)->count(),
            'errorCount' => $collection->where('importable', false)->count(),
            'assumedYearCount' => $collection->where('assumed_year', true)->count(),
            'receiptMatchCount' => $collection->whereNotNull('receipt_match_of')->count(),
            'ignoredCount' => $collection->whereNotNull('ignored_by')->count(),
            // 明細の請求額と突き合わせられるように、読み取れた合計を出す
            'expenseTotal' => (int) $importable->where('type', 'expense')->sum('amount'),
            'incomeTotal' => (int) $importable->where('type', 'income')->sum('amount'),
        ]);
    }

    public function confirm(Request $request, CsvImportService $service)
    {
        $expected = collect($request->session()->get(self::SESSION_KEY, []));

        if ($expected->isEmpty()) {
            return redirect()->route('imports.create')
                ->withErrors(['file' => '取り込み対象のデータがありません。もう一度CSVをアップロードしてください。']);
        }

        $token = $request->session()->get(self::SESSION_TOKEN);

        if ($token === null || (string) $request->input('token') !== (string) $token) {
            return redirect()->route('imports.create')
                ->withErrors(['file' => 'この取込はすでに登録されているか、期限切れです。取引一覧を確認してから、必要ならもう一度アップロードしてください。']);
        }

        // PHP の max_input_vars を超えるとPOSTが黙って切り捨てられる。
        // 気づかないまま「後半が入っていない」状態になるので、ここで止める。
        //
        // **バリデーションより先に見る。** 切り捨ては行の途中で起きるので、
        // 先にバリデーションを通すと「rows.166.amount には値が必要です」という
        // 原因の分からないエラーになり、この説明文に到達しない。
        $posted = $request->input('rows');
        $postedCount = is_array($posted) ? count($posted) : 0;

        if ($postedCount !== $expected->count()) {
            return back()->withInput()->withErrors(['rows' => sprintf(
                '送信された行数（%d件）が解析結果（%d件）と一致しません。'
                .'サーバーの上限（php.ini の max_input_vars、既定1000）で後半が切り捨てられた可能性があります。'
                .'1行あたり最大7個の入力があるため、既定値のままだと140行あたりが上限です。'
                .'安全のため登録していません。CSVを分割して取り込むか、max_input_vars を増やしてください。',
                $postedCount,
                $expected->count(),
            )]);
        }

        $validated = $request->validate([
            // 解析側と同じ上限。ここが無いと、巨大な配列を投げるだけで
            // バリデーションがメモリと時間を食う
            'rows' => ['required', 'array', 'min:1', 'max:'.CsvImportService::MAX_ROWS],
            'rows.*.import' => ['nullable', 'boolean'],
            'rows.*.transaction_date' => array_merge(
                ['required_with:rows.*.import', 'nullable'],
                DomainLimits::dateRules(),
            ),
            'rows.*.type' => ['required_with:rows.*.import', 'nullable', 'in:income,expense'],
            // 行ごとに、その行の種別に合うカテゴリだけを許す。
            // 学習ルールが収入カテゴリを指していると、支出行が数百件まとめて壊れる
            'rows.*.category_id' => Rule::forEach(function (mixed $value, string $attribute, array $data): array {
                // ここに渡ってくる $data は Arr::dot 済み（'rows.0.type' というキーがそのまま入る）。
                // data_get はドット付きの実キーを見ずに分解してしまうので必ず null になり、
                // 種別によるスコープが黙って効かなくなる。Arr::get は実キーを先に見る。
                return ['nullable', DomainLimits::categoryRule(
                    Arr::get($data, (string) preg_replace('/\.category_id$/', '.type', $attribute)),
                )];
            }),
            'rows.*.shop_name' => ['nullable', 'string', 'max:255'],
            'rows.*.amount' => array_merge(
                ['required_with:rows.*.import', 'nullable'],
                DomainLimits::amountRules(),
            ),
            'rows.*.ignore' => ['nullable', 'boolean'],
        ]);

        // 「今後この店は取り込まない」にチェックが付いた行を覚える。
        // 取り込むかどうかとは独立（今回は取り込むが次回からは外す、もできる）
        $learned = [];

        // rows[0] が文字列のような壊れた入力だと、rows.* のどのルールにも当たらず
        // validated() から 'rows' ごと消える（親の配列は「中身が検証済みの分だけ」返るため）。
        // $validated['rows'] を直接触ると Undefined array key で500になる。
        $rows = is_array($validated['rows'] ?? null) ? $validated['rows'] : [];

        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['ignore']) || empty($row['shop_name'])) {
                continue;
            }

            $rule = ImportIgnoreRule::remember($row['shop_name']);

            // 空白だけの店名は覚えられない。同じ店を2行ぶんチェックしても1件
            if ($rule !== null) {
                $learned[$rule->id] = true;
            }
        }

        $learnedIgnores = count($learned);

        // 行が配列でない入力（rows[0]=abc など）を渡されても落ちないようにする
        $targets = collect($rows)
            ->filter(fn ($row) => is_array($row) && ! empty($row['import']));

        if ($targets->isEmpty()) {
            if ($learnedIgnores > 0) {
                // 覚えた内容を画面に反映してから戻す。
                // 反映しないと、いま「取り込まない」にした行が取込可のまま
                // チェック付きで再表示され、そのまま登録できてしまう
                $this->refreshIgnoreMarks($request);

                return redirect()->route('imports.preview')
                    ->with('status', sprintf('%d件の店を「今後取り込まない」に登録しました。', $learnedIgnores));
            }

            return back()->withErrors(['rows' => '取り込む行が選択されていません。']);
        }

        $created = 0;
        $skipped = 0;
        $total = 0;
        $dates = [];
        $fileName = $request->session()->get(self::SESSION_FILE);

        // ダブルクリックで2本のリクエストが同時に走ると、どちらもセッションを
        // 読み終えたあとなのでトークンでは止まらない。ここで直列化する。
        // 直列化さえすれば、2本目は下の重複チェックで全件が見送られる。
        $lock = Cache::lock('csv-import-confirm', 60);

        if (! $lock->get()) {
            return back()->withInput()->withErrors([
                'rows' => 'いま登録の処理中です。数秒待ってから取引一覧を確認してください（二重に登録されることはありません）。',
            ]);
        }

        try {
            // 「取り込む前から登録されていたもの」をロックの内側で1回だけ調べる。
            // 1件入れるたびにDBを見ると、同じ日に同じ店で2回買った2件目が
            // 自分自身の1件目と一致して黙って消える。
            $existingKeys = $service->existingKeys(
                $targets->pluck('transaction_date')
                    ->filter()
                    ->map(fn ($date) => Carbon::parse($date)->toDateString())
                    ->all()
            );

            DB::transaction(function () use ($targets, $service, $fileName, $existingKeys, &$created, &$skipped, &$total, &$dates) {
                // 取込1回ぶんをまとめて取り消せるように、先に「取込」の記録を作る
                $batch = ImportBatch::create([
                    'file_name' => is_string($fileName) ? mb_substr($fileName, 0, 255) : null,
                    'source' => 'csv',
                ]);

                foreach ($targets as $row) {
                    $date = $row['transaction_date'];
                    $type = $row['type'];
                    $shop = $row['shop_name'] ?? null;
                    $amount = (int) $row['amount'];

                    // プレビューを開いている間にレシートから登録された分を二重計上しない。
                    // 判定は取り込み前のスナップショットに対して行う（同一内容の2行目を消さないため）
                    $key = $service->duplicateKey(Carbon::parse($date)->toDateString(), $type, $amount, $shop);

                    if (isset($existingKeys[$key])) {
                        $skipped++;

                        continue;
                    }

                    Transaction::create([
                        'transaction_date' => $date,
                        'type' => $type,
                        'category_id' => $row['category_id'] ?? null,
                        'shop_name' => $shop,
                        'memo' => 'CSV取込',
                        'amount' => $amount,
                        'import_batch_id' => $batch->id,
                    ]);

                    $created++;
                    $total += $type === 'expense' ? $amount : -$amount;
                    $dates[] = Carbon::parse($date)->toDateString();
                }

                if ($created === 0) {
                    // 1件も入らなかった取込の記録は残さない
                    $batch->delete();

                    return;
                }

                sort($dates);

                $batch->update([
                    'row_count' => $created,
                    'total_amount' => $total,
                    'first_date' => $dates[0] ?? null,
                    'last_date' => $dates[count($dates) - 1] ?? null,
                ]);
            });
        } finally {
            $lock->release();
        }

        // 次回、同じ並びのCSVで同じ手直しをしなくて済むように覚えておく
        $meta = (array) $request->session()->get(self::SESSION_META, []);

        // 見出しの行が分からないままの指定は覚えない。
        // 印はヘッダー行の中身から作るので、行が決まっていないと次回に当てようがない。
        $detectedMapping = (array) ($meta['detected'] ?? []);

        if (! empty($meta['manual'])
            && ! empty($meta['signature'])
            && ($detectedMapping['header_row'] ?? null) !== null) {
            try {
                ImportProfile::remember($meta['signature'], $meta['detected'], $meta['file_name'] ?? null);
            } catch (Throwable $e) {
                // 覚えられなくても取り込みは成功している。ここで500にしない
                report($e);
            }
        }

        $this->forgetSource($request);
        $request->session()->forget([self::SESSION_KEY, self::SESSION_TOKEN, self::SESSION_FILE, self::SESSION_META]);

        if ($created === 0) {
            return redirect()->route('transactions.index')->with(
                'status',
                sprintf('選んだ%d件はすでに登録済みでした。二重登録はしていません。', $skipped),
            );
        }

        $message = sprintf('CSVから%d件の取引を登録しました（差引 ¥%s）。', $created, number_format($total));

        if ($skipped > 0) {
            $message .= sprintf('すでに同じ内容が登録されていた%d件は見送りました。', $skipped);
        }

        if ($learnedIgnores > 0) {
            $message .= sprintf('%d件の店を「今後取り込まない」に登録しました。', $learnedIgnores);
        }

        if ($created > 0) {
            $message .= '間違えて取り込んだ場合は「取込履歴」からまとめて取り消せます。';
        }

        return redirect()->route('transactions.index')->with('status', $message);
    }
}
