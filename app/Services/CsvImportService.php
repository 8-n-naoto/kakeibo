<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * クレジットカード明細などのCSVを読み取り、取引候補の配列に変換するサービス。
 *
 * - 文字コードは Shift_JIS / UTF-8 を自動判定
 * - 列名(ヘッダー)から日付・利用先・金額の列を自動検出。検出できない場合は列位置で推測
 * - 金額がマイナスの行は返金とみなして収入(income)として扱う
 * - 既存の取引と「日付 + 金額 + 店名」が一致する行は重複候補としてマークする
 */
class CsvImportService
{
    /** 日付列の候補となるヘッダー名 */
    private const DATE_HEADERS = [
        '利用日', 'ご利用日', 'ご利用年月日', '利用年月日', '日付', '取引日', '使用日', '取引年月日', 'date',
    ];

    /** 利用先列の候補となるヘッダー名 */
    private const SHOP_HEADERS = [
        '利用店名', 'ご利用店名', 'ご利用先', '利用先', '店名', '内容', '摘要', '利用店名・商品名', 'description', 'memo',
    ];

    /** 金額列の候補となるヘッダー名 */
    private const AMOUNT_HEADERS = [
        '利用金額', 'ご利用金額', '支払金額', 'お支払金額', '請求額', '金額', '出金金額', '引落金額', 'amount',
    ];

    /** 店名からカテゴリを推測するためのキーワード辞書 */
    private const CATEGORY_KEYWORDS = [
        '食費' => ['スーパー', 'イオン', 'ライフ', '西友', 'マルエツ', 'サミット', '業務スーパー', 'オーケー', 'コープ', 'まいばすけっと'],
        '外食' => ['マクドナルド', 'スターバックス', 'ドトール', 'すき家', '吉野家', '松屋', 'サイゼリヤ', 'ガスト', '居酒屋', 'レストラン', 'カフェ'],
        '日用品' => ['ドラッグ', 'マツモトキヨシ', 'ウエルシア', 'サンドラッグ', 'ダイソー', 'ニトリ', 'カインズ', 'ホームセンター'],
        '水道光熱費' => ['電力', '電気', 'ガス', '水道', '東京ガス', '大阪ガス'],
        '通信費' => ['ドコモ', 'docomo', 'ソフトバンク', 'softbank', 'au', 'ｕｑ', 'uq', '楽天モバイル', 'nttコミュニケーションズ', 'インターネット', 'ワイモバイル'],
        '交通費' => ['jr', 'ｊｒ', 'suica', 'pasmo', 'モバイルsuica', 'タクシー', '鉄道', '交通', 'ｅｔｃ', 'etc', '高速', 'ana', 'jal'],
        '医療・健康' => ['薬局', 'クリニック', '医院', '病院', '歯科', 'ジム', 'フィットネス'],
        '衣服・美容' => ['ユニクロ', 'gu', 'zozo', '美容', 'ヘアー', '理容', 'しまむら'],
        '娯楽・趣味' => ['amazon', 'アマゾン', 'netflix', 'spotify', 'youtube', 'ゲーム', '映画', 'シネマ', '書店', '書房', 'ヨドバシ', 'ビックカメラ'],
        '住居費' => ['家賃', '管理費', '不動産'],
    ];

    /**
     * CSVファイルを解析して取引候補を返す。
     *
     * @return array{headers: array<int, string>, rows: array<int, array<string, mixed>>, detected: array<string, ?int>}
     */
    public function parse(string $absolutePath): array
    {
        if (! is_readable($absolutePath)) {
            throw new RuntimeException('CSVファイルを読み込めませんでした。');
        }

        $contents = file_get_contents($absolutePath);

        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException('CSVファイルが空です。');
        }

        $lines = $this->toLines($contents);

        if ($lines === []) {
            throw new RuntimeException('CSVファイルに読み取れる行がありません。');
        }

        $records = array_map(static fn (string $line) => str_getcsv($line, ',', '"', '\\'), $lines);
        $records = array_values(array_filter($records, static fn (array $row) => trim(implode('', array_map('strval', $row))) !== ''));

        if ($records === []) {
            throw new RuntimeException('CSVファイルに読み取れる行がありません。');
        }

        $headers = [];
        $detected = $this->detectColumns($records[0]);

        if ($detected['date'] !== null || $detected['amount'] !== null) {
            // 1行目はヘッダー行
            $headers = array_map(static fn ($v) => trim((string) $v), $records[0]);
            array_shift($records);
        } else {
            // ヘッダーが無いCSV。よくある「日付,利用先,金額」の並びを仮定する
            $detected = ['date' => 0, 'shop' => 1, 'amount' => $this->guessAmountColumn($records[0])];
        }

        $categories = Category::expense()->get();
        $rows = [];

        foreach ($records as $index => $record) {
            $rows[] = $this->buildRow($record, $detected, $categories, $index);
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'detected' => $detected,
        ];
    }

    /**
     * @param  array<int, string|null>  $record
     * @param  array<string, ?int>  $detected
     * @return array<string, mixed>
     */
    private function buildRow(array $record, array $detected, Collection $categories, int $index): array
    {
        $rawDate = $detected['date'] !== null ? ($record[$detected['date']] ?? null) : null;
        $rawShop = $detected['shop'] !== null ? ($record[$detected['shop']] ?? null) : null;
        $rawAmount = $detected['amount'] !== null ? ($record[$detected['amount']] ?? null) : null;

        $date = $this->parseDate((string) $rawDate);
        $amount = $this->parseAmount((string) $rawAmount);
        $shop = $this->normalize((string) $rawShop);

        $error = null;

        if ($date === null) {
            $error = '日付を読み取れませんでした';
        } elseif ($amount === null || $amount === 0) {
            $error = '金額を読み取れませんでした';
        }

        $type = ($amount !== null && $amount < 0) ? 'income' : 'expense';
        $absAmount = $amount !== null ? abs($amount) : 0;

        $duplicate = $error === null && $this->isDuplicate($date, $absAmount, $shop);

        return [
            'line' => $index + 1,
            'transaction_date' => $date,
            'shop_name' => $shop,
            'amount' => $absAmount,
            'type' => $type,
            'category_id' => $this->guessCategoryId($shop, $categories),
            'duplicate' => $duplicate,
            'error' => $error,
            'importable' => $error === null,
        ];
    }

    /**
     * 既に同じ日付・金額・店名の取引が登録されているか。
     */
    public function isDuplicate(?string $date, int $amount, ?string $shopName): bool
    {
        if ($date === null) {
            return false;
        }

        return Transaction::query()
            ->whereDate('transaction_date', $date)
            ->where('amount', $amount)
            ->when(
                $shopName !== null && $shopName !== '',
                fn ($query) => $query->where('shop_name', $shopName),
                fn ($query) => $query->whereNull('shop_name')
            )
            ->exists();
    }

    /**
     * 文字コードを UTF-8 に揃えて行配列に分解する。
     *
     * @return array<int, string>
     */
    private function toLines(string $contents): array
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
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $lines = preg_split("/\r\n|\r|\n/", $contents) ?: [];

        return array_values(array_filter($lines, static fn (string $line) => trim($line) !== ''));
    }

    /**
     * ヘッダー行から日付・利用先・金額の列位置を検出する。
     *
     * @param  array<int, string|null>  $header
     * @return array<string, ?int>
     */
    private function detectColumns(array $header): array
    {
        $normalized = array_map(fn ($value) => $this->normalizeHeader((string) $value), $header);

        return [
            'date' => $this->findColumn($normalized, self::DATE_HEADERS),
            'shop' => $this->findColumn($normalized, self::SHOP_HEADERS),
            'amount' => $this->findColumn($normalized, self::AMOUNT_HEADERS),
        ];
    }

    /**
     * @param  array<int, string>  $normalizedHeader
     * @param  array<int, string>  $candidates
     */
    private function findColumn(array $normalizedHeader, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $needle = $this->normalizeHeader($candidate);

            foreach ($normalizedHeader as $index => $value) {
                if ($value !== '' && $value === $needle) {
                    return $index;
                }
            }
        }

        // 完全一致しない場合は部分一致でもう一度
        foreach ($candidates as $candidate) {
            $needle = $this->normalizeHeader($candidate);

            foreach ($normalizedHeader as $index => $value) {
                if ($value !== '' && $needle !== '' && str_contains($value, $needle)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * ヘッダーが無いCSVで、数値が入っている最後の列を金額列とみなす。
     *
     * @param  array<int, string|null>  $record
     */
    private function guessAmountColumn(array $record): ?int
    {
        $found = null;

        foreach ($record as $index => $value) {
            if ($this->parseAmount((string) $value) !== null) {
                $found = $index;
            }
        }

        return $found;
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

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /**
     * 「2026/01/05」「2026-01-05」「2026年1月5日」「1/5」などを Y-m-d に変換する。
     */
    public function parseDate(string $value): ?string
    {
        $value = trim(mb_convert_kana($value, 'as'));

        if ($value === '') {
            return null;
        }

        $value = str_replace(['年', '月'], '/', str_replace('日', '', $value));
        $value = str_replace(['.', '-'], '/', $value);
        $value = preg_replace('/\s+/', '', $value) ?? $value;

        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $value, $m)) {
            return $this->makeDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
            return $this->makeDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $value, $m)) {
            return $this->makeDate((int) Carbon::now()->year, (int) $m[1], (int) $m[2]);
        }

        return null;
    }

    private function makeDate(int $year, int $month, int $day): ?string
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::create($year, $month, $day)->toDateString();
    }

    /**
     * 「1,234円」「¥1,234」「-1234」などを整数に変換する。返金(マイナス)は負数のまま返す。
     */
    public function parseAmount(string $value): ?int
    {
        $value = trim(mb_convert_kana($value, 'as'));

        if ($value === '') {
            return null;
        }

        $negative = str_contains($value, '-') || str_contains($value, '△') || str_contains($value, '▲');
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        $amount = (int) $digits;

        return $negative ? -$amount : $amount;
    }

    /**
     * 店名のキーワードからカテゴリを推測する。
     */
    public function guessCategoryId(?string $shopName, Collection $categories): ?int
    {
        if ($shopName === null || $shopName === '') {
            return null;
        }

        $haystack = mb_strtolower(mb_convert_kana($shopName, 'asKV'));

        foreach (self::CATEGORY_KEYWORDS as $categoryName => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, mb_strtolower(mb_convert_kana($keyword, 'asKV')))) {
                    return $categories->firstWhere('name', $categoryName)?->id;
                }
            }
        }

        return null;
    }
}
