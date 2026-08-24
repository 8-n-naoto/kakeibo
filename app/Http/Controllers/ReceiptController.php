<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ReceiptImage;
use App\Models\Transaction;
use App\Services\AbstractReceiptParser;
use App\Services\MerchantCategoryGuesser;
use App\Services\ReceiptImageCleaner;
use App\Services\ReceiptImageStore;
use App\Services\ReceiptParser;
use App\Rules\SupportedReceiptImage;
use App\Support\ReceiptValueNormalizer;
use App\Support\DomainLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ReceiptController extends Controller
{
    /**
     * 1回のアップロードで受け付ける最大枚数。
     *
     * アップロードは保存するだけでAIを呼ばないので、枚数を増やしてもタイムアウトしない。
     * （解析は「未登録レシート」画面から1枚ずつ別リクエストで行う）
     * .env の RECEIPT_MAX_FILES_PER_UPLOAD で変更できる。
     */
    private const DEFAULT_MAX_FILES_PER_UPLOAD = 20;

    /** 同じレシートを同時に読み取らないためのロック時間（秒） */
    private const PARSE_LOCK_SECONDS = 120;

    /** 失敗一覧に出す最大件数 */
    private const FAILED_LIST_LIMIT = 50;

    /** 1枚のレシートから登録できる品目の上限（レシート1枚にこれ以上の行は無い） */
    private const MAX_ITEMS_PER_RECEIPT = 200;

    /** 一括登録で一度に扱うレシートの上限 */
    private const MAX_ROWS_PER_REGISTER = 200;

    /**
     * 1日にAIへ投げられる最大枚数。
     *
     * AI呼び出しは1枚ごとに課金される。画面の自動読み取りが何かの拍子に
     * 繰り返しになると請求が青天井になるので、サーバ側で必ず頭を打たせる。
     * .env の RECEIPT_AI_DAILY_LIMIT で変更できる。
     */
    private const DEFAULT_DAILY_PARSE_LIMIT = 200;

    /**
     * error_message に残す文字数の上限。
     *
     * AIのエラーはレスポンス本文をそのまま含むことがあり、画像をそのまま返してくる例もある。
     * 長すぎると text 列に入りきらず、保存そのものが例外になって 500 を返してしまう。
     */
    private const ERROR_MESSAGE_LIMIT = 500;

    /** 1日あたりのAI呼び出し上限（1以上、上限1000） */
    public static function dailyParseLimit(): int
    {
        $configured = (int) config('services.receipt_ai.daily_limit', self::DEFAULT_DAILY_PARSE_LIMIT);

        return max(1, min($configured, 1000));
    }

    /** 今日すでにAIへ投げた枚数 */
    public static function parsedToday(): int
    {
        return (int) Cache::get(self::dailyParseKey(), 0);
    }

    private static function dailyParseKey(): string
    {
        return 'receipt-parse-count:'.now()->format('Y-m-d');
    }

    public static function maxFilesPerUpload(): int
    {
        // config 経由にしておく（env() を直接読むと config:cache 後に効かなくなる）
        $configured = (int) config('services.receipt.max_files_per_upload', self::DEFAULT_MAX_FILES_PER_UPLOAD);

        return max(1, min($configured, 50));
    }

    public function create()
    {
        return view('receipts.upload', [
            'pendingCount' => ReceiptImage::awaitingParse()->count() + ReceiptImage::readyForRegistration()->count(),
            'maxFiles' => self::maxFilesPerUpload(),
        ]);
    }

    /**
     * 画像をアップロードして保存する。**この時点では AI を呼ばない。**
     *
     * 以前は1リクエストの中で枚数分のAI呼び出しを逐次に行っていたが、
     * 「枚数 × 最大60秒」が php-fpm / httpd のタイムアウトを超えると
     * 502/504 で途中終了し、status=pending の行だけが残っていた。
     * いまは保存だけして「未登録レシート」画面へ送り、解析は1枚=1リクエストで行う。
     */
    public function store(Request $request, ReceiptImageStore $imageStore)
    {
        $maxFiles = self::maxFilesPerUpload();

        $request->validate([
            'images' => ['required', 'array', 'max:'.$maxFiles],
            // mimes ルールは libmagic 依存で HEIC を弾くことがあるため、自前のルールで見る
            'images.*' => ['required', 'file', 'max:10240', new SupportedReceiptImage],
        ], [
            'images.required' => '画像ファイルを選択してください。',
            'images.max' => '一度にアップロードできるのは'.$maxFiles.'枚までです。',
            'images.*.max' => '1枚あたり10MBまでです。',
        ]);

        $uploaded = 0;

        foreach ($request->file('images') as $file) {
            ReceiptImage::create([
                'path' => $imageStore->store($file),
                'status' => 'pending',
            ]);

            $uploaded++;
        }

        // 自動開始の合図はクエリ文字列に持たせる。
        // セッションのフラッシュだと、遷移直後にリロードされただけで消えてしまう。
        return redirect()
            ->route('receipts.pending', ['autostart' => 1])
            ->with('status', $uploaded.'枚をアップロードしました。読み取りを始めます。');
    }

    /**
     * レシート1枚をAIで解析する。
     *
     * 1リクエスト＝1枚に固定しているので、何枚アップロードしてもタイムアウトしない。
     * 画面のJavaScriptから順番に呼ばれる（JSが無い環境ではボタンで1枚ずつ）。
     */
    public function parse(Request $request, ReceiptImage $receiptImage, ReceiptParser $parser)
    {
        if ($receiptImage->isRegistered()) {
            return $this->parseResponse($request, $receiptImage, false, 'このレシートはすでに登録済みです。');
        }

        // 自動での読み取り対象は「まだ読んでいない」か「失敗した」ものだけ。
        // すでに読み取り済みのものを勝手に読み直すと AI の課金が二重に発生する。
        //
        // ただし、人が画面のボタンで明示的に頼んだとき（retry=1）は読み直す。
        // AI が「JSONとしては妥当だが中身の無い」結果を返すと processed で固まってしまい、
        // 二度とAIに読ませられない「詰んだレシート」ができるため。
        // 失敗しても直前の解析結果は下の catch で残す。
        $retry = $request->boolean('retry');

        if (! $retry && ! in_array($receiptImage->status, ['pending', 'failed'], true)) {
            return $this->parseResponse($request, $receiptImage, true, 'すでに読み取り済みです。');
        }

        // 別タブや二重送信で同じ画像を同時にAIへ送らないようにする（課金が二重になる）
        $lock = Cache::lock('receipt-parse:'.$receiptImage->getKey(), self::PARSE_LOCK_SECONDS);

        if (! $lock->get()) {
            return $this->parseResponse($request, $receiptImage, false, '別の画面で読み取り中です。少し待ってからやり直してください。');
        }

        try {
            // AIを呼ぶ直前に必ず頭を打たせる。画面が壊れて読み取りを繰り返しても、
            // 1日でこの枚数を超えたらそれ以上は課金されない。
            if (! $this->consumeDailyQuota()) {
                return $this->parseResponse(
                    $request,
                    $receiptImage,
                    false,
                    '今日のAI読み取り上限（'.self::dailyParseLimit().'枚）に達しました。'
                        .'明日以降に続けるか、.env の RECEIPT_AI_DAILY_LIMIT を見直してください。',
                    429,
                    // 同じ429でもルートの throttle と区別できるようにする
                    'daily_limit',
                );
            }

            $result = $parser->parse(Storage::disk('public')->path($receiptImage->path));

            $receiptImage->update([
                'status' => 'processed',
                'raw_response' => $result['raw_response'] ?? null,
                // セッションのフラッシュだけだとリロードで消えるのでDBにも残す
                'parsed_data' => $result,
                'error_message' => null,
            ]);

            return $this->parseResponse($request, $receiptImage, true, '読み取りました。');
        } catch (Throwable $e) {
            $message = $this->markFailed($receiptImage, $e);

            return $this->parseResponse($request, $receiptImage, false, '読み取りに失敗しました: '.$message);
        } finally {
            $lock->release();
        }
    }

    /**
     * 今日のAI呼び出し枠を1つ消費する。使い切っていたら false。
     */
    private function consumeDailyQuota(): bool
    {
        $key = self::dailyParseKey();
        $limit = self::dailyParseLimit();

        // 日付が変わるまで保持する。add してから increment すると、
        // 別タブと同時に叩かれても数え落とさない。
        Cache::add($key, 0, now()->endOfDay());

        // database ストアは行が無いと increment が false を返す。
        // 0 とみなすと上限が効かなくなるので、数えられなかったら止める側に倒す。
        $incremented = Cache::increment($key);
        $used = is_numeric($incremented) ? (int) $incremented : $limit + 1;

        if ($used > $limit) {
            // 弾いた分まで数えると「3140 / 200」のような表示になってしまう
            if (is_numeric($incremented)) {
                Cache::decrement($key);
            }

            return false;
        }

        return true;
    }

    /**
     * 読み取り失敗を記録する。ここで例外を投げてはいけない。
     *
     * 500 を返すと status が pending のまま残り、画面が自動で読み直して
     * AI呼び出しが延々と繰り返される（＝課金が止まらない）。
     * だからエラー文は必ず切り詰め、保存に失敗しても状態だけは pending から動かす。
     *
     * @return string 画面に返す（切り詰め済みの）エラー文
     */
    private function markFailed(ReceiptImage $receiptImage, Throwable $e): string
    {
        // 成功パスの update() が途中で落ちた場合、モデルには保存されていない
        // parsed_data が載ったままになっている。それを見て判断すると
        // 「DBは空なのに processed」という詰んだ行ができるので、まず捨てる。
        $receiptImage->discardChanges();

        // すでに使える解析結果があるなら、失敗扱いに落として一覧から消さない
        $keepProcessed = ! empty($receiptImage->parsed_data);
        $status = $keepProcessed ? 'processed' : 'failed';

        $message = self::shortErrorMessage($e);

        try {
            $receiptImage->update([
                'status' => $status,
                'error_message' => $message,
            ]);
        } catch (Throwable $ignored) {
            try {
                ReceiptImage::whereKey($receiptImage->getKey())
                    ->toBase()
                    ->update([
                        'status' => $status,
                        // 本文が原因で落ちた可能性があるので、さらに短くして入れ直す
                        'error_message' => mb_substr($message, 0, 200),
                    ]);
            } catch (Throwable $alsoIgnored) {
                // ここまで落ちるならDB側の障害。状態を書けなくても例外は外に出さない
                // （500 を返すと pending のまま画面が読み直し、AI課金が止まらない）
            }

            $receiptImage->setAttribute('status', $status);
        }

        return $message;
    }

    /**
     * 例外メッセージを、DBにも画面にも安全な短さに切り詰める。
     *
     * Laravel の Str::limit は「表示幅」で切るため、制御文字だらけのバイナリ本文だと
     * 幅が 0 と数えられて何も切られない。文字数で確実に切る。
     */
    private static function shortErrorMessage(Throwable $e): string
    {
        $raw = (string) $e->getMessage();

        // 不正なUTF-8が混ざっていると preg_replace('/u') が null を返して本文が消える
        $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-8');

        // 空白だけでなく制御文字もまとめて潰す
        $raw = (string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $raw);
        $message = trim($raw);

        if (mb_strlen($message) > self::ERROR_MESSAGE_LIMIT) {
            $message = mb_substr($message, 0, self::ERROR_MESSAGE_LIMIT).'…';
        }

        return $message === '' ? $e::class : $message;
    }

    /**
     * JavaScript から呼ばれたときは JSON、フォーム送信のときはリダイレクトで返す。
     */
    private function parseResponse(Request $request, ReceiptImage $receiptImage, bool $ok, string $message, ?int $httpStatus = null, ?string $reason = null)
    {
        if ($request->expectsJson() || $request->boolean('ajax')) {
            return response()->json([
                'ok' => $ok,
                'id' => $receiptImage->id,
                'status' => $receiptImage->status,
                'message' => $message,
                'reason' => $reason,
            ], $httpStatus ?? ($ok ? 200 : 422));
        }

        $redirect = redirect()->route('receipts.pending');

        return $ok
            ? $redirect->with('status', $message)
            : $redirect->withErrors(['parse' => $message]);
    }

    /**
     * 解析済みでまだ家計簿に登録していないレシートの一覧。まとめて確認・登録する。
     */
    public function pending(Request $request, MerchantCategoryGuesser $guesser, ReceiptImageCleaner $cleaner)
    {
        $categories = Category::orderBy('type')->orderBy('sort_order')->get();

        $rows = ReceiptImage::readyForRegistration()->orderBy('id')->get()
            ->map(function (ReceiptImage $receipt) use ($categories, $guesser) {
                $parsed = $this->displayableParsed($receipt->parsed_data);

                return [
                    'receipt' => $receipt,
                    'parsed' => $parsed,
                    'warnings' => $parsed['warnings'] ?? [],
                    'suggested_category_id' => $this->suggestCategoryId($parsed, $categories, $guesser),
                ];
            })
            // 確かめたほうがよいものを先に見せる
            ->sortByDesc(fn (array $row): int => count($row['warnings']))
            ->values();

        return view('receipts.pending', [
            'rows' => $rows,
            // まだAIに読ませていないもの（アップロード直後）
            'unparsed' => ReceiptImage::awaitingParse()->orderBy('id')->get(),
            'failedReceipts' => ReceiptImage::where('status', 'failed')
                ->whereDoesntHave('transactions')
                ->orderByDesc('id')
                ->limit(self::FAILED_LIST_LIMIT)
                ->get(),
            'failedTotal' => ReceiptImage::where('status', 'failed')->whereDoesntHave('transactions')->count(),
            'autoStart' => $request->boolean('autostart'),
            // AIの使いすぎが目に見えるようにしておく
            'aiUsedToday' => self::parsedToday(),
            'aiDailyLimit' => self::dailyParseLimit(),
            'categories' => $categories,
            // 画面表示のたびにディスク全走査をしないよう、DBから分かる分だけ数える
            'cleanup' => $cleaner->preview(includeOrphanFiles: false),
            'retentionDays' => ReceiptImageCleaner::DEFAULT_RETENTION_DAYS,
        ]);
    }

    /**
     * 使われていないレシート画像を片付ける。取引が紐づいているものは対象外。
     */
    public function cleanup(ReceiptImageCleaner $cleaner)
    {
        $result = $cleaner->prune();

        if ($result['records'] === 0 && $result['files'] === 0) {
            return redirect()->route('receipts.pending')->with('status', '片付ける画像はありませんでした。');
        }

        return redirect()->route('receipts.pending')->with('status', sprintf(
            'レシート画像を%d件（%s）片付けました。',
            $result['files'],
            $this->formatBytes($result['bytes']),
        ));
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1).'MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024).'KB';
        }

        return $bytes.'B';
    }

    /**
     * 一括確認画面から、チェックしたレシートをまとめて取引として登録する。
     */
    public function bulkStore(Request $request)
    {
        $input = $request->input('rows');

        // filter する前に、生の件数でも頭を打つ。
        // 画面に出るのは未登録レシートだけなので、これを超える送信は通常あり得ない。
        if (is_array($input) && count($input) > self::MAX_ROWS_PER_REGISTER * 5) {
            return redirect()
                ->route('receipts.pending')
                ->withErrors(['rows' => '一度に扱える件数を超えています。画面を読み直してください。']);
        }

        $rows = collect(is_array($input) ? $input : [])
            ->filter(fn ($row) => is_array($row) && ! empty($row['selected']));

        if ($rows->isEmpty()) {
            return redirect()
                ->route('receipts.pending')
                ->withErrors(['rows' => '登録するレシートを1件以上選んでください。']);
        }

        Validator::make(['rows' => $rows->all()], [
            // 1件ごとに lockForUpdate を掛けるので、件数に上限が要る
            'rows' => ['required', 'array', 'max:'.self::MAX_ROWS_PER_REGISTER],
            'rows.*.transaction_date' => array_merge(['required'], DomainLimits::dateRules()),
            'rows.*.type' => ['required', 'in:income,expense'],
            'rows.*.category_id' => Rule::forEach(function (mixed $value, string $attribute, array $data): array {
                // ここに渡ってくる $data は Arr::dot 済み（'rows.0.type' というキーがそのまま入る）。
                // data_get はドット付きの実キーを見ずに分解してしまうので必ず null になり、
                // 種別によるスコープが黙って効かなくなる。Arr::get は実キーを先に見る。
                return ['nullable', DomainLimits::categoryRule(
                    Arr::get($data, (string) preg_replace('/\.category_id$/', '.type', $attribute)),
                )];
            }),
            'rows.*.shop_name' => ['nullable', 'string', 'max:255'],
            'rows.*.memo' => ['nullable', 'string', 'max:255'],
            'rows.*.amount' => array_merge(['required'], DomainLimits::amountRules()),
        ], [
            'rows.*.transaction_date.required' => '日付を入力してください。',
            'rows.*.amount.required' => '金額を入力してください。',
            'rows.*.amount.integer' => '金額は整数で入力してください。',
        ])->validate();

        $registered = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, &$registered, &$skipped) {
            foreach ($rows as $receiptId => $row) {
                // 行ロックを取ってから判定する（ほぼ同時に2本送られても二重計上しない）
                $receiptImage = ReceiptImage::whereKey($receiptId)->lockForUpdate()->first();

                if (! $receiptImage || $receiptImage->isRegistered()) {
                    $skipped++;

                    continue;
                }

                Transaction::create([
                    'transaction_date' => $row['transaction_date'],
                    'type' => $row['type'],
                    'category_id' => ($row['category_id'] ?? null) ?: null,
                    'shop_name' => $row['shop_name'] ?? null,
                    'memo' => $row['memo'] ?? null,
                    'amount' => (int) $row['amount'],
                    'receipt_image_id' => $receiptImage->id,
                ]);

                $registered++;
            }
        });

        $message = $registered.'件の取引を登録しました。';

        if ($skipped > 0) {
            $message .= '（'.$skipped.'件は登録済みのためスキップしました）';
        }

        return redirect()->route('receipts.pending')->with('status', $message);
    }

    /**
     * 解析結果を確認・修正して家計簿に登録する画面。
     */
    public function confirm(Request $request, ReceiptImage $receiptImage, MerchantCategoryGuesser $guesser)
    {
        // リロード・ブラウザバックでもフォームが空にならないよう、DBに保存した解析結果を使う
        $parsed = $request->session()->get('parsed') ?? $receiptImage->parsed_data;
        $parsed = is_array($parsed) ? $this->displayableParsed($parsed) : null;
        $categories = Category::orderBy('type')->orderBy('sort_order')->get();

        $suggestedCategoryId = $this->suggestCategoryId($parsed, $categories, $guesser);

        return view('receipts.confirm', [
            'receiptImage' => $receiptImage,
            'parsed' => $parsed,
            'categories' => $categories,
            'suggestedCategoryId' => $suggestedCategoryId,
            'registeredTransactions' => $receiptImage->transactions()->with('category')->get(),
        ]);
    }

    /**
     * 確認画面から実際の取引として保存。
     *
     * mode=total  … レシート合計を1件の取引として登録（従来どおり）
     * mode=items  … 読み取れた品目ごとに取引を分けて登録
     *               （スーパーで食費と日用品を一緒に買ったときにカテゴリを分けられる）
     */
    public function confirmStore(Request $request, ReceiptImage $receiptImage)
    {
        // ブラウザバックからの再送信で同じレシートが二重計上されるのを防ぐ
        if ($receiptImage->isRegistered()) {
            return $this->alreadyRegisteredResponse();
        }

        if ($request->input('mode') === 'items') {
            return $this->storeAsItems($request, $receiptImage);
        }

        $validated = $request->validate([
            'transaction_date' => array_merge(['required'], DomainLimits::dateRules()),
            'type' => ['required', 'in:income,expense'],
            'category_id' => ['nullable', DomainLimits::categoryRule($request->input('type'))],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:255'],
            'amount' => array_merge(['required'], DomainLimits::amountRules()),
        ]);

        $registered = $this->registerOnce($receiptImage, function (ReceiptImage $locked) use ($validated) {
            Transaction::create($validated + ['receipt_image_id' => $locked->id]);
        });

        if (! $registered) {
            return $this->alreadyRegisteredResponse();
        }

        return redirect()->route('dashboard')->with('status', 'レシートから取引を登録しました。');
    }

    /**
     * レシートに行ロックを掛けてから、まだ未登録のときだけ $callback を実行する。
     *
     * 確認画面のダブルタップやPWAのリトライ送信で、同じレシートが
     * 2件の取引になるのを防ぐ。
     */
    private function registerOnce(ReceiptImage $receiptImage, callable $callback): bool
    {
        return (bool) DB::transaction(function () use ($receiptImage, $callback) {
            $locked = ReceiptImage::whereKey($receiptImage->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->isRegistered()) {
                return false;
            }

            $callback($locked);

            return true;
        });
    }

    private function alreadyRegisteredResponse()
    {
        return redirect()
            ->route('transactions.index')
            ->with('status', 'このレシートはすでに登録済みです。金額を直す場合は取引一覧から編集してください。');
    }

    /**
     * 品目ごとに取引を分けて登録する。
     *
     * 日付・種別・店名はレシート単位で共通、品目名はメモに入れる。
     */
    private function storeAsItems(Request $request, ReceiptImage $receiptImage)
    {
        $validated = $request->validate([
            'transaction_date' => array_merge(['required'], DomainLimits::dateRules()),
            'type' => ['required', 'in:income,expense'],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS_PER_RECEIPT],
            'items.*.selected' => ['nullable'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.amount' => array_merge(['nullable'], DomainLimits::amountRules()),
            // 品目は1枚のレシートに属するので、種別はレシート単位の $request->type
            'items.*.category_id' => ['nullable', DomainLimits::categoryRule($request->input('type'))],
        ], [
            'items.*.amount.integer' => '品目の金額は整数で入力してください。',
        ]);

        $selected = collect($validated['items'])
            ->filter(fn ($item) => is_array($item) && ! empty($item['selected']));

        if ($selected->isEmpty()) {
            return back()
                ->withInput()
                ->withErrors(['items' => '登録する品目を1つ以上選んでください。']);
        }

        $missingAmount = $selected->contains(fn (array $item) => ($item['amount'] ?? null) === null);

        if ($missingAmount) {
            return back()
                ->withInput()
                ->withErrors(['items' => '選んだ品目の金額をすべて入力してください。']);
        }

        $registered = $this->registerOnce($receiptImage, function (ReceiptImage $locked) use ($selected, $validated) {
            foreach ($selected as $item) {
                Transaction::create([
                    'transaction_date' => $validated['transaction_date'],
                    'type' => $validated['type'],
                    'category_id' => $item['category_id'] ?? null,
                    'shop_name' => $validated['shop_name'] ?? null,
                    'memo' => $item['name'] ?? null,
                    'amount' => (int) $item['amount'],
                    'receipt_image_id' => $locked->id,
                ]);
            }
        });

        if (! $registered) {
            return $this->alreadyRegisteredResponse();
        }

        $total = (int) $selected->sum(fn (array $item) => (int) $item['amount']);
        $receiptTotal = $this->displayableParsed($receiptImage->parsed_data)['total_amount'] ?? null;

        $message = $selected->count().'件の取引を品目ごとに登録しました。（合計 ¥'.number_format($total).'）';

        if ($receiptTotal !== null && (int) $receiptTotal !== $total) {
            $difference = $total - (int) $receiptTotal;
            $message .= ' レシート合計 ¥'.number_format((int) $receiptTotal)
                .' との差は '.($difference > 0 ? '+' : '-').'¥'.number_format(abs($difference)).' です。';
        }

        return redirect()->route('dashboard')->with('status', $message);
    }

    /**
     * 画面に出しても安全な形に解析結果を整える。
     *
     * この修正より前に保存されたレコードには、AIが返した配列やオブジェクトが
     * そのまま入っている可能性がある。Blade でそれを出力すると TypeError になり、
     * 1件のせいで一覧全体が開けなくなるので、ここで必ず落としておく。
     *
     * @param  mixed  $parsed
     * @return array<string, mixed>
     */
    private function displayableParsed(mixed $parsed): array
    {
        if (! is_array($parsed)) {
            return [];
        }

        // 保存経路（AbstractReceiptParser）と同じ正規化を通す。
        // 別々に書くと「保存では1200なのに画面では空」のようなずれが生まれるため、
        // ロジックは ReceiptValueNormalizer に一本化している。
        $parsed['shop_name'] = ReceiptValueNormalizer::text($parsed['shop_name'] ?? null);
        $parsed['memo'] = ReceiptValueNormalizer::text($parsed['memo'] ?? null);
        $parsed['suggested_category'] = ReceiptValueNormalizer::text($parsed['suggested_category'] ?? null);
        $parsed['total_amount'] = ReceiptValueNormalizer::amount($parsed['total_amount'] ?? null);
        $parsed['transaction_date'] = ReceiptValueNormalizer::date($parsed['transaction_date'] ?? null);
        $parsed['items'] = ReceiptValueNormalizer::items($parsed['items'] ?? null);

        // 古い解析結果には warnings が入っていないので、表示のたびに計算し直す
        $parsed['warnings'] = AbstractReceiptParser::warningsFor(
            $parsed['total_amount'],
            $parsed['transaction_date'],
            $parsed['items'],
        );

        return $parsed;
    }

    /**
     * 確認画面で最初に選んでおくカテゴリ。
     *
     * 1. 学習ルール（過去に同じ店名で人が確定させたカテゴリ）
     * 2. AI がレシートを見て出した提案
     * 3. 店名のキーワード辞書
     * の順に見る。
     *
     * @param  array<string, mixed>|null  $parsed
     * @param  \Illuminate\Support\Collection<int, Category>  $categories
     */
    private function suggestCategoryId(?array $parsed, $categories, MerchantCategoryGuesser $guesser): ?int
    {
        if (! $parsed) {
            return null;
        }

        $shopName = $parsed['shop_name'] ?? null;

        // 1. 過去に同じ店名で登録した実績（人が確定させたもの）
        $learned = $guesser->guessByRule($shopName);

        if ($learned !== null) {
            return $learned;
        }

        // 2. AIがレシートを見て出した提案
        // レシートは支出なので、支出カテゴリの中からしか選ばない。
        // 「給与」のような収入カテゴリを提案されることがあり、そのまま初期選択にすると
        // 登録時に種別違いで弾かれる（画面には理由が出ない）
        if (! empty($parsed['suggested_category'])) {
            $suggested = collect($categories)
                ->firstWhere(fn ($category) => $category->type === 'expense'
                    && $category->name === $parsed['suggested_category'])?->id;

            if ($suggested !== null) {
                return $suggested;
            }
        }

        // 3. 店名のキーワード辞書（AIが提案しなかった／知らないカテゴリ名だったとき）
        // 辞書は名前だけで照合するので、ここも支出に絞る。
        // 「交通費（払い戻し）」のような収入カテゴリが当たると、初期選択のまま保存して弾かれる
        return MerchantCategoryGuesser::guessByKeyword(
            $shopName,
            collect($categories)->where('type', 'expense'),
        );
    }

}
