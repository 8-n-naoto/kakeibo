<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ImportIgnoreRule;
use App\Models\Transaction;
use App\Support\DomainLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * クレジットカード明細などのCSVを読み取り、取引候補の配列に変換するサービス。
 *
 * - 文字コードは Shift_JIS / UTF-8 を自動判定
 * - 区切り文字はカンマ / タブ / セミコロンを自動判定
 * - ヘッダー行はファイル先頭 HEADER_SCAN_ROWS 行から探す
 *   （「○○カードご利用代金明細」のような前置き行があるCSVが実際に多いため）
 * - 出金列と入金列が分かれている銀行CSVにも対応する
 * - 金額がマイナスの行は返金とみなして収入(income)として扱う
 * - 既存の取引と「日付 + 種別 + 金額 + 店名」が一致する行は重複候補としてマークする
 *
 * ■ 金額と日付の読み取りで守っていること
 *   **読めないものは null にする。もっともらしい数字をでっち上げない。**
 *   以前は「数字以外を全部消す」実装だったため `1234.00` が 123400 に、
 *   `1,234 (内消費税112円)` が 1234112 になっていた。画面には読み取れたように見えるので、
 *   気づかないまま100倍の支出が登録される。読めないなら赤字で止めたほうがよい。
 */
class CsvImportService
{
    /** 一度に扱える最大行数（セッション・POST変数・重複チェックのいずれもここで頭打ちにする） */
    public const MAX_ROWS = 2000;

    /** ヘッダー行を探す範囲（ファイル先頭から何行ぶんか） */
    private const HEADER_SCAN_ROWS = 10;

    /** レシートとカード明細の突合で許す日付のずれ（日） */
    public const RECEIPT_MATCH_DAYS = 3;

    /** 日付列の候補となるヘッダー名 */
    private const DATE_HEADERS = [
        '利用日', 'ご利用日', 'ご利用年月日', '利用年月日', '日付', '取引日', '使用日', '取引年月日', '取引日時', 'date',
    ];

    /** 利用先列の候補となるヘッダー名 */
    private const SHOP_HEADERS = [
        '利用店名', 'ご利用店名', 'ご利用先', '利用先', '店名', '内容', '摘要', '利用店名・商品名', 'description', 'memo',
    ];

    /** 金額（出金）列の候補となるヘッダー名 */
    private const AMOUNT_HEADERS = [
        '利用金額', 'ご利用金額', '支払金額', 'お支払金額', 'お支払額', 'ご請求額', '請求額', '金額', '出金金額', '引落金額', 'お引出し', '出金', 'amount',
    ];

    /** 入金列の候補となるヘッダー名（出金と分かれている銀行CSV用） */
    private const INCOME_HEADERS = [
        '入金金額', 'お預り金額', 'お預入れ金額', '預入金額', '入金額', '入金', 'お預入れ',
    ];

    /** 和暦の元号 → 元年の前年 */
    private const ERAS = [
        '令和' => 2018, 'r' => 2018,
        '平成' => 1988, 'h' => 1988,
        '昭和' => 1925, 's' => 1925,
    ];

    /**
     * @param  MerchantCategoryGuesser|null  $guesser  学習済みルールを使う場合に渡す。
     *                                                  null のときはキーワード辞書だけで推測する。
     */
    public function __construct(private readonly ?MerchantCategoryGuesser $guesser = null)
    {
    }

    /**
     * CSVファイルを解析して取引候補を返す。
     *
     * @return array{headers: array<int, string>, rows: array<int, array<string, mixed>>, detected: array<string, ?int>}
     */
    public function parse(string $absolutePath, ?array $mapping = null): array
    {
        if (! is_readable($absolutePath)) {
            throw new RuntimeException('CSVファイルを読み込めませんでした。');
        }

        $contents = file_get_contents($absolutePath);

        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException('CSVファイルが空です。');
        }

        $records = $this->toRecords($this->toUtf8($contents));

        if ($records === []) {
            throw new RuntimeException('CSVファイルに読み取れる行がありません。');
        }

        $allRecords = $records;

        // 自動判定は常に走らせる。手で列を指定された場合でも、
        // 「このCSVの見出しはどの行か」だけは自動判定の結果から印を作る。
        // 解析結果から作ると、列を手で直した瞬間に別ファイル扱いになって
        // 覚えた対応を次回に当てられない。
        [$autoHeaders, $autoDetected] = $this->splitHeader($allRecords);
        $autoHeaderRow = $autoDetected['header_row'] ?? null;

        [$headers, $detected, $records] = $mapping === null
            ? $this->splitHeader($records)
            : $this->applyMapping($records, $mapping);

        if (count($records) > self::MAX_ROWS) {
            throw new RuntimeException(sprintf(
                '行数が多すぎます（%s行）。一度に取り込めるのは%s行までです。期間を分けて出力してください。',
                number_format(count($records)),
                number_format(self::MAX_ROWS),
            ));
        }

        $categories = Category::expense()->get();

        // 年の書かれていない「1/5」形式のために、ファイル全体から基準の年を決める
        $parsedDates = array_map(fn (array $record): ?array => $this->splitDate($this->cell($record, $detected['date'])), $records);
        $years = $this->yearsForRows($parsedDates, $this->referenceYear($parsedDates));

        $rows = [];

        foreach ($records as $index => $record) {
            $rows[] = $this->buildRow(
                $record,
                $detected,
                $categories,
                $index,
                $parsedDates[$index],
                $years[$index] ?? (int) Carbon::now()->format('Y'),
            );
        }

        $this->markDuplicates($rows);
        $this->markReceiptMatches($rows);
        $this->markRepeatsWithinFile($rows);
        $this->markIgnored($rows);

        return [
            'headers' => $headers,
            'rows' => $rows,
            'detected' => $detected,
            // 列の対応を手で直すための材料
            'columns' => $this->columnLabels($allRecords, $autoHeaders === [] ? $headers : $autoHeaders),
            'row_labels' => $this->rowLabels($allRecords),
            'header_row' => $detected['header_row'] ?? null,
            // 同じカード会社のCSVを見分ける印。見出しの行が分からないCSVは null（覚えない）
            'signature' => $this->signatureOf($allRecords, $autoHeaderRow),
        ];
    }

    /**
     * 画面で指定された列の対応をそのまま使う。
     *
     * 自動判定は当たらないことがあり、当たらないとユーザーにできることが
     * 「CSVをテキストエディタで直す」しかなくなる。逃げ道を用意する。
     *
     * @param  array<int, array<int, string|null>>  $records
     * @param  array<string, ?int>  $mapping
     * @return array{0: array<int, string>, 1: array<string, ?int>, 2: array<int, array<int, string|null>>}
     */
    private function applyMapping(array $records, array $mapping): array
    {
        $headerRow = $mapping['header_row'] ?? null;
        $headerRow = is_int($headerRow) && $headerRow >= 0 && $headerRow < count($records) ? $headerRow : null;

        $detected = [
            'date' => $this->columnIndex($mapping['date'] ?? null),
            'shop' => $this->columnIndex($mapping['shop'] ?? null),
            'amount' => $this->columnIndex($mapping['amount'] ?? null),
            'income' => $this->columnIndex($mapping['income'] ?? null),
            'header_row' => $headerRow,
        ];

        if ($headerRow === null) {
            return [[], $detected, array_values($records)];
        }

        $headers = array_map(static fn ($v) => trim((string) $v), $records[$headerRow]);

        return [$headers, $detected, array_values(array_slice($records, $headerRow + 1))];
    }

    private function columnIndex(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_scalar($value) || ! ctype_digit((string) $value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * 列を選ぶプルダウンに出す見出し。ヘッダーが無ければ「1列目」のように出す。
     *
     * @param  array<int, array<int, string|null>>  $records
     * @param  array<int, string>  $headers
     * @return array<int, string>
     */
    private function columnLabels(array $records, array $headers): array
    {
        $widths = array_map(static fn (array $record): int => count($record), $records);
        $count = max(count($headers), $widths === [] ? 0 : max($widths));

        $labels = [];

        for ($i = 0; $i < $count; $i++) {
            $name = trim((string) ($headers[$i] ?? ''));
            $labels[$i] = $name === '' ? ($i + 1).'列目' : ($i + 1).'列目: '.mb_substr($name, 0, 20);
        }

        return $labels;
    }

    /**
     * 同じカード会社のCSVかどうかを見分ける印。
     *
     * ヘッダー行の**中身**だけから作る。「解析の結果どう切ったか」から作ると、
     * 列の対応を手で直した瞬間に別のファイル扱いになり、覚えた対応を次回当てられない。
     *
     * @param  array<int, array<int, string|null>>  $records
     */
    private function signatureOf(array $records, ?int $headerRow): ?string
    {
        // 見出しの行が見つからないCSVは、列数くらいでしか見分けようがない。
        // 列数だけで「同じカード会社」と決めつけると、まったく別のCSVに
        // 前回の対応を当ててしまうので、その場合は覚えないことにする。
        if ($headerRow === null || ! isset($records[$headerRow])) {
            return null;
        }

        $cells = array_map(fn ($v): string => $this->normalizeHeader((string) $v), $records[$headerRow]);

        return sha1('h:'.implode('|', $cells));
    }

    /**
     * ヘッダー行を選ぶプルダウンに出す見出し。
     *
     * 列のプルダウンを使い回すと**列数ぶんの行しか選べず**、
     * 前置きが4行あるCSVで本物のヘッダー行を選べない。
     *
     * @param  array<int, array<int, string|null>>  $records
     * @return array<int, string>
     */
    private function rowLabels(array $records): array
    {
        $labels = [];

        foreach (array_slice($records, 0, self::HEADER_SCAN_ROWS) as $index => $record) {
            $preview = implode(', ', array_map(
                static fn ($v): string => trim((string) $v),
                array_slice($record, 0, 4),
            ));

            $labels[$index] = ($index + 1).'行目: '.mb_substr($preview, 0, 40);
        }

        return $labels;
    }

    /**
     * @param  array<int, string|null>  $record
     * @param  array<string, ?int>  $detected
     * @param  array{year: ?int, month: int, day: int}|null  $dateParts
     * @return array<string, mixed>
     */
    private function buildRow(
        array $record,
        array $detected,
        Collection $categories,
        int $index,
        ?array $dateParts,
        int $referenceYear,
    ): array {
        $rawDate = $this->cell($record, $detected['date']);
        $rawShop = $this->cell($record, $detected['shop']);
        $rawAmount = $this->cell($record, $detected['amount']);
        $rawIncome = $this->cell($record, $detected['income'] ?? null);

        $assumedYear = $dateParts !== null && $dateParts['year'] === null;
        $date = $dateParts === null
            ? null
            : $this->makeDate($dateParts['year'] ?? $referenceYear, $dateParts['month'], $dateParts['day']);

        $amount = $this->parseAmount($rawAmount);

        // 出金列が空で入金列に数字がある銀行CSV。入金はマイナス（＝収入）として扱う
        if (($amount === null || $amount === 0) && $rawIncome !== '') {
            $income = $this->parseAmount($rawIncome);

            if ($income !== null && $income !== 0) {
                $amount = -abs($income);
            }
        }

        $shop = $this->normalize($rawShop);

        $error = null;

        if ($date === null) {
            if ($rawDate === '') {
                $error = '日付の列が空です';
            } elseif ($dateParts !== null && $dateParts['year'] === null) {
                // 年が書かれていない行。読めなかったのは書式ではなく、
                // 近くの行から補った年が範囲外だったということ
                $error = sprintf(
                    '年が書かれていないため補えませんでした（%s）。%s〜%s の範囲で、年から入力してください',
                    mb_substr($rawDate, 0, 30),
                    DomainLimits::minDate(),
                    DomainLimits::maxDate(),
                );
            } else {
                $error = sprintf(
                    '日付を読み取れませんでした（%s）。%s〜%s の範囲で入力してください',
                    mb_substr($rawDate, 0, 30),
                    DomainLimits::minDate(),
                    DomainLimits::maxDate(),
                );
            }
        } elseif ($amount === null || $amount === 0) {
            $shown = $rawAmount !== '' ? $rawAmount : $rawIncome;
            $error = $shown === ''
                ? '金額の列が空です'
                : sprintf(
                    '金額を読み取れませんでした（%s）。%s円までの整数で入力してください',
                    mb_substr($shown, 0, 30),
                    number_format(DomainLimits::MAX_AMOUNT),
                );
        }

        $type = ($amount !== null && $amount < 0) ? 'income' : 'expense';
        $absAmount = $amount !== null ? abs($amount) : 0;

        return [
            'line' => $index + 1,
            'transaction_date' => $date,
            'assumed_year' => $error === null && $assumedYear,
            'shop_name' => $shop,
            'amount' => $absAmount,
            'type' => $type,
            // 推測できるのは支出カテゴリだけ（$categories は支出のみ）。
            // 返金行（入金＝収入）に支出カテゴリを載せると、プレビューは緑なのに
            // 確定でカテゴリの種別違いとして弾かれる、という分かりにくい失敗になる。
            'category_id' => $type === 'expense'
                ? ($this->guesser?->guess($shop, $categories) ?? $this->guessCategoryId($shop, $categories))
                : null,
            'duplicate' => false,
            'duplicate_of' => null,
            'receipt_match_of' => null,
            'repeat_of_line' => null,
            'ignored_by' => null,
            'error' => $error,
            'importable' => $error === null,
        ];
    }

    /**
     * 既存の取引と突き合わせて重複候補に印を付ける。
     *
     * 1行ずつ SELECT を撃つと数百行のCSVで数百クエリになるので、
     * 対象日の取引をまとめて引いてからPHP側で突き合わせる。
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function markDuplicates(array &$rows): void
    {
        $dates = collect($rows)->pluck('transaction_date')->filter()->unique()->values();

        if ($dates->isEmpty()) {
            return;
        }

        $existing = Transaction::query()
            ->whereIn('transaction_date', $dates->all())
            ->get(['id', 'transaction_date', 'type', 'amount', 'shop_name']);

        if ($existing->isEmpty()) {
            return;
        }

        $index = [];

        foreach ($existing as $transaction) {
            $index[$this->duplicateKey(
                $transaction->transaction_date?->toDateString(),
                $transaction->type,
                (int) $transaction->amount,
                $transaction->shop_name,
            )][] = $transaction;
        }

        foreach ($rows as &$row) {
            if (! $row['importable']) {
                continue;
            }

            $key = $this->duplicateKey($row['transaction_date'], $row['type'], $row['amount'], $row['shop_name']);
            $match = $index[$key][0] ?? null;

            if ($match === null) {
                continue;
            }

            $row['duplicate'] = true;
            // 「重複候補」とだけ出しても判断できないので、何と一致したのかを見せる
            $row['duplicate_of'] = sprintf(
                '%s %s ¥%s',
                $match->transaction_date?->format('n/j') ?? '',
                $match->shop_name ?? '（店名なし）',
                number_format((int) $match->amount),
            );
        }
    }

    /**
     * 「レシートで登録済みの同じ支払い」を探して印を付ける。
     *
     * この運用では同じ買い物が2回入る経路がある。
     *   1. 買った日にレシートを撮って登録する
     *   2. 月末にカード明細のCSVを取り込む
     *
     * 店名の書き方が全く違う（レシート「スーパーライフ 中野店」／CSV「ﾗｲﾌ ﾅｶﾉ」）ので、
     * 日付＋店名＋金額の重複判定には**絶対に引っかからない**。放っておくと支出が静かに二重になる。
     *
     * 金額の完全一致は非常に強いシグナルなので、「金額が同じ・数日以内・レシート由来」
     * の取引があれば候補として見せる。落とすかどうかは人が決める。
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function markReceiptMatches(array &$rows): void
    {
        $targets = [];

        foreach ($rows as $index => $row) {
            if (! $row['importable'] || $row['duplicate'] || $row['type'] !== 'expense' || $row['amount'] <= 0) {
                continue;
            }

            $targets[$index] = $row;
        }

        if ($targets === []) {
            return;
        }

        $amounts = array_values(array_unique(array_column($targets, 'amount')));
        $dates = array_column($targets, 'transaction_date');
        sort($dates);

        $from = Carbon::parse($dates[0])->subDays(self::RECEIPT_MATCH_DAYS)->toDateString();
        $to = Carbon::parse($dates[count($dates) - 1])->addDays(self::RECEIPT_MATCH_DAYS)->toDateString();

        $candidates = Transaction::query()
            ->whereNotNull('receipt_image_id')
            ->where('type', 'expense')
            ->whereIn('amount', $amounts)
            ->whereBetween('transaction_date', [$from, $to])
            ->orderBy('transaction_date')
            ->get(['id', 'transaction_date', 'amount', 'shop_name']);

        if ($candidates->isEmpty()) {
            return;
        }

        /** @var array<int, true> $used 1件のレシートを複数行に割り当てない */
        $used = [];

        foreach ($targets as $index => $row) {
            $rowDate = Carbon::parse($row['transaction_date']);
            $best = null;
            $bestDistance = PHP_INT_MAX;

            foreach ($candidates as $candidate) {
                if (isset($used[$candidate->id]) || (int) $candidate->amount !== $row['amount']) {
                    continue;
                }

                $distance = abs($rowDate->diffInDays($candidate->transaction_date));

                if ($distance <= self::RECEIPT_MATCH_DAYS && $distance < $bestDistance) {
                    $best = $candidate;
                    $bestDistance = $distance;
                }
            }

            if ($best === null) {
                continue;
            }

            $used[$best->id] = true;

            $rows[$index]['receipt_match_of'] = sprintf(
                '%s %s ¥%s（レシートから登録済み）',
                $best->transaction_date?->format('n/j') ?? '',
                $best->shop_name ?? '（店名なし）',
                number_format((int) $best->amount),
            );
        }
    }

    /**
     * 同じファイルの中で内容がまったく同じ行に印を付ける。
     *
     * 同じ日に同じ店で同じ金額の買い物を2回する（コンビニでコーヒー2杯など）のは
     * 普通にあるので、勝手に落とさない。ただし**確定時の重複チェックで
     * 2件目が黙って消える**ので、そうなる前に「同じ内容の行が上にある」と伝える。
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function markRepeatsWithinFile(array &$rows): void
    {
        $seen = [];

        foreach ($rows as &$row) {
            if (! $row['importable']) {
                continue;
            }

            $key = $this->duplicateKey($row['transaction_date'], $row['type'], $row['amount'], $row['shop_name']);

            if (isset($seen[$key])) {
                $row['repeat_of_line'] = $seen[$key];

                continue;
            }

            $seen[$key] = $row['line'];
        }
    }

    /**
     * 「取り込まない」ルールに当たる行に印を付ける。
     *
     * 落とさずに印を付けるだけにしてある。黙って消すと、
     * ルールを作ったことを忘れたころに「なぜか支出が少ない」という
     * 気づけない事故になる。既定のチェックを外すところまでが仕事。
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function markIgnored(array &$rows): void
    {
        $rules = ImportIgnoreRule::query()->get();

        if ($rules->isEmpty()) {
            return;
        }

        foreach ($rows as &$row) {
            if (! $row['importable']) {
                continue;
            }

            $row['ignored_by'] = ImportIgnoreRule::matchIn($rules, $row['shop_name']);
        }
    }

    /**
     * 重複判定のキー。店名の全角/半角・大小文字・空白のゆれは吸収する。
     */
    public function duplicateKey(?string $date, string $type, int $amount, ?string $shopName): string
    {
        return implode('|', [$date, $type, $amount, MerchantCategoryGuesser::normalize($shopName)]);
    }

    /**
     * 指定した日付にすでに登録されている取引のキー一覧。
     *
     * 登録確定のときは、これを**登録を始める前に1回だけ**取っておく。
     * 1件入れるたびにDBへ問い合わせると、同じ日に同じ店で2回買った2件目が
     * 「すでに登録済み」として黙って消える。
     *
     * @param  array<int, string>  $dates
     * @return array<string, true>
     */
    public function existingKeys(array $dates): array
    {
        $dates = array_values(array_unique(array_filter($dates)));

        if ($dates === []) {
            return [];
        }

        $keys = [];

        Transaction::query()
            ->whereIn('transaction_date', $dates)
            ->select(['id', 'transaction_date', 'type', 'amount', 'shop_name'])
            ->orderBy('id')
            ->chunk(1000, function ($chunk) use (&$keys) {
                foreach ($chunk as $transaction) {
                    $key = $this->duplicateKey(
                        $transaction->transaction_date?->toDateString(),
                        $transaction->type,
                        (int) $transaction->amount,
                        $transaction->shop_name,
                    );

                    $keys[$key] = true;
                }
            });

        return $keys;
    }

    /**
     * 既に同じ日付・種別・金額・店名の取引が登録されているか。
     * 登録確定時の再チェック（プレビューを開いたまま別経路で登録された場合）に使う。
     */
    public function isDuplicate(?string $date, int $amount, ?string $shopName, string $type = 'expense'): bool
    {
        if ($date === null) {
            return false;
        }

        return Transaction::query()
            ->whereDate('transaction_date', $date)
            ->where('type', $type)
            ->where('amount', $amount)
            ->when(
                $shopName !== null && $shopName !== '',
                fn ($query) => $query->where('shop_name', $shopName),
                fn ($query) => $query->whereNull('shop_name')
            )
            ->exists();
    }

    /**
     * 文字コードを UTF-8 に揃える。
     */
    private function toUtf8(string $contents): string
    {
        // mb_detect_encoding は日本語CSVで誤判定しやすいので、mb_check_encoding で順に確かめる
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $converted = null;

            foreach (['SJIS-win', 'CP932', 'EUC-JP'] as $encoding) {
                if (mb_check_encoding($contents, $encoding)) {
                    $converted = mb_convert_encoding($contents, 'UTF-8', $encoding);
                    break;
                }
            }

            // どれにも当てはまらない場合は Shift_JIS とみなして変換する
            $contents = $converted ?? mb_convert_encoding($contents, 'UTF-8', 'SJIS-win');
        }

        // BOM除去
        return preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
    }

    /**
     * CSVをレコード配列にする。
     *
     * 自前で改行分割してから str_getcsv に渡すと、引用符の中に改行がある行
     * （銀行CSVの摘要欄によくある）が2行に割れて壊れる。fgetcsv に任せる。
     *
     * @return array<int, array<int, string|null>>
     */
    private function toRecords(string $contents): array
    {
        // 旧Mac形式（CRだけ）への保険。CRLF/LF は fgetcsv が扱える
        if (! str_contains($contents, "\n")) {
            $contents = str_replace("\r", "\n", $contents);
        }

        $delimiter = $this->detectDelimiter($contents);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('CSVを読み込めませんでした。');
        }

        fwrite($handle, $contents);
        rewind($handle);

        $records = [];

        try {
            // エスケープ文字は使わない（RFC4180）。既定の '\' だと
            // 「末尾がバックスラッシュの値」で以降の列と行を飲み込む
            while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if (! is_array($record) || $record === [null]) {
                    continue;
                }

                if (trim(implode('', array_map(static fn ($v) => (string) $v, $record))) === '') {
                    continue;
                }

                $records[] = $record;

                // 上限を超えたら読み進めない（巨大ファイルでメモリを食い潰さない）
                if (count($records) > self::MAX_ROWS + self::HEADER_SCAN_ROWS) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $records;
    }

    /**
     * 区切り文字を推測する。カンマ / タブ / セミコロン。
     *
     * 出現回数の多さでは決めない。前置き行のカンマや、摘要欄に入っている
     * カンマ（`ﾆﾎﾝｾｲﾒｲ,ﾎｹﾝﾘｮｳ,7ｶﾞﾂﾌﾞﾝ` など）で、タブ区切りのファイルが
     * カンマ区切りと判定されてしまう。
     * 「同じ列数の行がいちばん多く並ぶ区切り」を選ぶ。
     */
    private function detectDelimiter(string $contents): string
    {
        $sample = array_slice(
            array_values(array_filter(
                preg_split("/\r\n|\r|\n/", $contents) ?: [],
                static fn (string $line): bool => trim($line) !== '',
            )),
            0,
            20,
        );

        if ($sample === []) {
            return ',';
        }

        $best = ',';
        $bestScore = 0;

        foreach ([',', "\t", ';'] as $delimiter) {
            $counts = [];

            foreach ($sample as $line) {
                $fields = count(str_getcsv($line, $delimiter, '"', ''));

                if ($fields >= 2) {
                    $counts[$fields] = ($counts[$fields] ?? 0) + 1;
                }
            }

            if ($counts === []) {
                continue;
            }

            // 「同じ列数で並んでいる行数」×「その列数」を点数にする。
            // 列数が多いほうが、たまたま揃っただけの可能性が低い
            $modeCount = max($counts);
            $modeFields = (int) array_search($modeCount, $counts, true);
            $score = $modeCount * $modeFields;

            if ($score > $bestScore) {
                $best = $delimiter;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * ヘッダー行を探して、ヘッダー・列位置・データ行に分ける。
     *
     * 1行目だけを見ていると、「○○カードご利用代金明細」のような前置き行が
     * 1行あるだけで全行が読み取り失敗になる。先頭数行から探す。
     *
     * @param  array<int, array<int, string|null>>  $records
     * @return array{0: array<int, string>, 1: array<string, ?int>, 2: array<int, array<int, string|null>>}
     */
    private function splitHeader(array $records): array
    {
        $scan = array_slice($records, 0, self::HEADER_SCAN_ROWS, true);
        $headerIndex = null;
        $detected = null;

        // まず日付と金額の両方が見つかる行を探す
        foreach ($scan as $index => $record) {
            $candidate = $this->detectColumns($record);

            if ($candidate['date'] !== null && ($candidate['amount'] !== null || $candidate['income'] !== null)) {
                $headerIndex = $index;
                $detected = $candidate;
                break;
            }
        }

        // 見つからなければ、片方だけでも見つかる行から**いちばん良いもの**を選ぶ。
        // 先頭から順に採用すると、`合計金額,52340` のような前置き行が
        // 本物のヘッダー行を差し置いてヘッダーになってしまう
        if ($headerIndex === null) {
            $bestScore = 0;

            foreach ($scan as $index => $record) {
                $candidate = $this->detectColumns($record);

                $score = ($candidate['date'] !== null ? 2 : 0)
                    + ($candidate['amount'] !== null ? 2 : 0)
                    + ($candidate['income'] !== null ? 1 : 0)
                    + ($candidate['shop'] !== null ? 1 : 0);

                if ($score === 0) {
                    continue;
                }

                // 同点なら、列の多い（＝本物のヘッダーらしい）行を採る
                $score = $score * 100 + count(array_filter($record, static fn ($v) => trim((string) $v) !== ''));

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $headerIndex = $index;
                    $detected = $candidate;
                }
            }
        }

        if ($headerIndex !== null && $detected !== null) {
            $headers = array_map(static fn ($v) => trim((string) $v), $records[$headerIndex]);
            $detected['header_row'] = $headerIndex;

            return [$headers, $detected, array_values(array_slice($records, $headerIndex + 1))];
        }

        // ヘッダーが無いCSV。よくある「日付,利用先,金額」の並びを仮定する
        return [
            [],
            [
                'date' => 0,
                'shop' => 1,
                'amount' => $this->guessAmountColumn($records),
                'income' => null,
                'header_row' => null,
            ],
            array_values($records),
        ];
    }

    /**
     * ヘッダー行から日付・利用先・金額・入金の列位置を検出する。
     *
     * @param  array<int, string|null>  $header
     * @return array<string, ?int>
     */
    private function detectColumns(array $header): array
    {
        $normalized = array_map(fn ($value) => $this->normalizeHeader((string) $value), $header);

        // 入金列を先に決める。`入金金額` は `金額` を含むので、
        // 出金列を先に探すと部分一致で入金列を掴んでしまう
        $income = $this->findColumn($normalized, self::INCOME_HEADERS, []);
        $amount = $this->findColumn($normalized, self::AMOUNT_HEADERS, $income === null ? [] : [$income]);

        return [
            'date' => $this->findColumn($normalized, self::DATE_HEADERS, []),
            'shop' => $this->findColumn($normalized, self::SHOP_HEADERS, []),
            'amount' => $amount,
            'income' => $income,
        ];
    }

    /**
     * @param  array<int, string>  $normalizedHeader
     * @param  array<int, string>  $candidates
     * @param  array<int, int>  $exclude  既に別の役割で使った列
     */
    private function findColumn(array $normalizedHeader, array $candidates, array $exclude): ?int
    {
        foreach ($candidates as $candidate) {
            $needle = $this->normalizeHeader($candidate);

            foreach ($normalizedHeader as $index => $value) {
                if ($value !== '' && $value === $needle && ! in_array($index, $exclude, true)) {
                    return $index;
                }
            }
        }

        // 完全一致しない場合は部分一致でもう一度
        foreach ($candidates as $candidate) {
            $needle = $this->normalizeHeader($candidate);

            foreach ($normalizedHeader as $index => $value) {
                if ($value !== '' && $needle !== '' && str_contains($value, $needle) && ! in_array($index, $exclude, true)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * ヘッダーが無いCSVで金額列を推測する。
     * 1行目だけだと当てにならないので、先頭20行で「金額として読めた回数」が最も多い列を選ぶ。
     *
     * @param  array<int, array<int, string|null>>  $records
     */
    private function guessAmountColumn(array $records): ?int
    {
        $hits = [];

        foreach (array_slice($records, 0, 20) as $record) {
            foreach ($record as $index => $value) {
                if ($index === 0) {
                    // 0列目は日付とみなしているので金額の候補にしない
                    continue;
                }

                if ($this->parseAmount((string) $value) !== null) {
                    $hits[$index] = ($hits[$index] ?? 0) + 1;
                }
            }
        }

        if ($hits === []) {
            return null;
        }

        // 同数なら左の列。右を選ぶと `日付,摘要,金額,残高` で「残高」を拾ってしまい、
        // 毎行に口座残高が取引として入る
        ksort($hits);

        $best = null;
        $bestCount = 0;

        foreach ($hits as $index => $count) {
            if ($count > $bestCount) {
                $best = $index;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, string|null>  $record
     */
    private function cell(array $record, ?int $index): string
    {
        if ($index === null) {
            return '';
        }

        return trim((string) ($record[$index] ?? ''));
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim($value);
        $value = mb_convert_kana($value, 'asKV');

        return mb_strtolower(str_replace([' ', '　', '"'], '', $value));
    }

    private function normalize(string $value): ?string
    {
        $value = trim($value);

        // このアプリのCSVエクスポートは、数式として実行されないように
        // 特定の文字で始まる値の頭に `'` を足している。取り込み直したときに
        // それが店名に残らないようにする（往復するたびに増えていく）。
        // 判定はエクスポータ側の定数を使うので、対象文字がずれることはない。
        $value = TransactionCsvExporter::unescapeFormula($value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /**
     * 「2026/01/05」「2026-01-05」「2026年1月5日」「1/5」などを Y-m-d に変換する。
     * 年が書かれていない場合は今年とみなす（parse() 経由ならファイル全体から基準年を決める）。
     */
    public function parseDate(string $value): ?string
    {
        $parts = $this->splitDate($value);

        if ($parts === null) {
            return null;
        }

        return $this->makeDate($parts['year'] ?? (int) Carbon::now()->format('Y'), $parts['month'], $parts['day']);
    }

    /**
     * 日付文字列を年・月・日に分解する。年が書かれていなければ year は null。
     *
     * @return array{year: ?int, month: int, day: int}|null
     */
    private function splitDate(string $value): ?array
    {
        $text = trim(mb_convert_kana($value, 'as'));

        if ($text === '') {
            return null;
        }

        // 曜日（(月) や （月））と時刻を落とす
        $text = (string) preg_replace('/[（(]\s*[日月火水木金土]\s*[）)]/u', '', $text);
        $text = (string) preg_replace('/\s*\d{1,2}:\d{1,2}(:\d{1,2})?\s*$/', '', $text);
        $text = trim($text);

        // 和暦（令和8年1月5日 / R8.1.5 / H31/4/30）
        if (preg_match('/^(令和|平成|昭和|[RHSrhs])\s*(\d{1,2}|元)\s*[年.\/\-]\s*(\d{1,2})\s*[月.\/\-]\s*(\d{1,2})\s*日?$/u', $text, $m)) {
            $era = self::ERAS[$m[1]] ?? self::ERAS[mb_strtolower($m[1])] ?? null;

            if ($era !== null) {
                $yearInEra = $m[2] === '元' ? 1 : (int) $m[2];

                return ['year' => $era + $yearInEra, 'month' => (int) $m[3], 'day' => (int) $m[4]];
            }
        }

        $text = str_replace(['年', '月'], '/', str_replace('日', '', $text));
        $text = str_replace(['.', '-'], '/', $text);
        $text = (string) preg_replace('/\s+/', '', $text);

        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $text, $m)) {
            return ['year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3]];
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $text, $m)) {
            return ['year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3]];
        }

        // 2桁年（26/01/05）。`01/05/26`（米国式の月/日/年）と見分けが付かないので、
        // 「今年の前後数年」に収まるときだけ年とみなす
        if (preg_match('/^(\d{2})\/(\d{1,2})\/(\d{1,2})$/', $text, $m)) {
            $year = 2000 + (int) $m[1];
            $thisYear = (int) Carbon::now()->format('Y');

            if ($year >= $thisYear - 5 && $year <= $thisYear + 1) {
                return ['year' => $year, 'month' => (int) $m[2], 'day' => (int) $m[3]];
            }

            return null;
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $text, $m)) {
            return ['year' => null, 'month' => (int) $m[1], 'day' => (int) $m[2]];
        }

        return null;
    }

    /**
     * 年の書かれていない行のための基準年を決める。
     *
     * 12月分の明細を1月に取り込むと「今年」で補完した瞬間に1年ずれる。
     * 同じファイルに年つきの行があればその年を、無ければ今年を使い、
     * 未来になりすぎる場合は前年とみなす。
     *
     * @param  array<int, array{year: ?int, month: int, day: int}|null>  $parsed
     */
    private function referenceYear(array $parsed): int
    {
        $now = Carbon::now();
        $thisYear = (int) $now->format('Y');

        foreach ($parsed as $parts) {
            if ($parts !== null && $parts['year'] !== null) {
                // 年つきの行が1つでもあれば、その年を基準にする（下で行ごとに近い年へ寄せる）
                return $parts['year'];
            }
        }

        // 年つきの行が1つも無い場合。今年で置いたときに1ヶ月以上先になるなら前年とみなす
        foreach ($parsed as $parts) {
            if ($parts === null || $parts['year'] !== null) {
                continue;
            }

            $candidate = $this->makeDate($thisYear, $parts['month'], $parts['day']);

            if ($candidate !== null && Carbon::parse($candidate)->greaterThan($now->copy()->addMonth())) {
                return $thisYear - 1;
            }
        }

        return $thisYear;
    }

    /**
     * 年の書かれていない行に、いちばん近い「年つきの行」の年を当てる。
     *
     * 明細は新しい順に並んでいることも古い順に並んでいることもあるので、
     * 「最後の年つきの行」を一律に使うと、年をまたぐファイルで1年ずれる。
     *
     * @param  array<int, array{year: ?int, month: int, day: int}|null>  $parsed
     * @return array<int, int> 行番号 => 使う年
     */
    private function yearsForRows(array $parsed, int $fallbackYear): array
    {
        $years = [];
        $known = [];

        foreach ($parsed as $index => $parts) {
            if ($parts !== null && $parts['year'] !== null) {
                $known[$index] = $parts['year'];
            }
        }

        foreach ($parsed as $index => $parts) {
            if ($parts === null) {
                continue;
            }

            if ($parts['year'] !== null) {
                $years[$index] = $parts['year'];

                continue;
            }

            if ($known === []) {
                $years[$index] = $fallbackYear;

                continue;
            }

            // いちばん行番号の近い「年つきの行」を探す
            $nearest = null;
            $nearestDistance = PHP_INT_MAX;

            foreach ($known as $knownIndex => $year) {
                $distance = abs($knownIndex - $index);

                if ($distance < $nearestDistance) {
                    $nearestDistance = $distance;
                    $nearest = $year;
                }
            }

            $years[$index] = $nearest ?? $fallbackYear;
        }

        return $years;
    }

    private function makeDate(int $year, int $month, int $day): ?string
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $date = Carbon::create($year, $month, $day)->toDateString();

        // 集計側（年間まとめ・ダッシュボード・予算）は2000年以降しか見ない。
        // ここを通すと「一覧には出るのに、どの集計にも出ない取引」ができてしまい、
        // 「合計が合わない」という追えない壊れ方をする。元号の誤読で 1899 になる明細が実在する。
        if (! DomainLimits::inDateRange($date)) {
            return null;
        }

        return $date;
    }

    /**
     * 「1,234円」「¥1,234」「-1234」「(1,234)」「1234.00」などを整数に変換する。
     * 返金(マイナス)は負数のまま返す。
     *
     * 数字以外を全部消す実装にはしない。`1234.00` が 123400 になり、
     * 画面上は読み取れたように見えるまま100倍の支出が登録されてしまうため。
     * 読めない値は null にして、プレビューで赤字にする。
     */
    public function parseAmount(string $value): ?int
    {
        $text = trim(mb_convert_kana($value, 'as'));

        if ($text === '') {
            return null;
        }

        // 会計形式の (1,234) はマイナス
        $parenthesized = (bool) preg_match('/^\((.*)\)$/', $text, $m);

        if ($parenthesized) {
            $text = trim($m[1]);
        }

        // 通貨記号・空白だけ落とす（区切りと符号はまだ残す）
        $body = str_replace(['¥', '￥', '円', ' ', '　'], '', $text);

        // 符号は**先頭か末尾に1つだけ**。途中に入っているものは金額ではない。
        // 全部まとめて消すと `2026-08-01` が -20260801、`03-1234-5678` が
        // -312345678 という「もっともらしい金額」になってしまう。
        $sign = 1;

        if (preg_match('/^([+\-−△▲])(.*)$/u', $body, $m)) {
            $sign = $m[1] === '+' ? 1 : -1;
            $body = $m[2];
        } elseif (preg_match('/^(.*)([+\-−])$/u', $body, $m)) {
            $sign = $m[2] === '+' ? 1 : -1;
            $body = $m[1];
        }

        if ($parenthesized) {
            $sign = -1;
        }

        $body = str_replace('，', ',', $body);

        if ($body === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $body) === 1) {
            // 1,234 / 1,234.56（カンマが3桁区切り）
            $cleaned = str_replace(',', '', $body);
        } elseif (preg_match('/^\d+(\.\d+)?$/', $body) === 1) {
            // 1234 / 1234.00
            $cleaned = $body;
        } else {
            // `1.234,56`（ヨーロッパ式）や `1-2-3` のような読めない形
            return null;
        }

        // `1.234` は 1234 とも 1.234 とも読める。家計簿に1円未満は出てこないので、
        // 当てずっぽうで決めずに読めないものとして扱う
        if (preg_match('/^\d{1,3}\.\d{3}$/', $cleaned) === 1) {
            return null;
        }

        // 桁が多すぎる値は float→int で壊れる（負の巨大値になる）ので弾く
        if ((float) $cleaned > (float) PHP_INT_MAX) {
            return null;
        }

        $amount = (int) round((float) $cleaned);

        // 家計簿の1取引としてありえない桁は、読めなかった扱いにしてプレビューで赤字にする。
        // ここを通すと、確定時の合計が溢れて取込が丸ごと500になる。
        if (! DomainLimits::inAmountRange($amount)) {
            return null;
        }

        return $sign < 0 ? -$amount : $amount;
    }

    /**
     * 店名のキーワードからカテゴリを推測する(固定辞書のみ)。
     *
     * 学習済みルールを含めた推測は MerchantCategoryGuesser::guess() を使う。
     */
    public function guessCategoryId(?string $shopName, Collection $categories): ?int
    {
        return MerchantCategoryGuesser::guessByKeyword($shopName, $categories);
    }
}
