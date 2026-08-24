<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * AI が返した値を家計簿で扱える形に揃える。
 *
 * AI は型を保証してくれないので、`shop_name` が配列だったり
 * `total_amount` が "¥1,200" だったりする。
 * ここを通さずに画面へ出すと、1件の壊れたレコードで一覧ごと開けなくなる。
 *
 * 保存時（AbstractReceiptParser）と表示時（ReceiptController）の両方から呼ぶ。
 * 片方だけ直すと「保存経路では1200なのに画面では空」のようなずれが生まれるため、
 * ロジックはこのクラス1か所に置く。
 */
final class ReceiptValueNormalizer
{
    /** 文字列項目の最大長（DBのカラム長に合わせる） */
    public const MAX_TEXT_LENGTH = 255;

    /**
     * 文字列として扱える値だけを取り出す。配列やオブジェクトは捨てる。
     */
    public static function text(mixed $value, int $maxLength = self::MAX_TEXT_LENGTH): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $maxLength);
    }

    /**
     * 「1,200」「¥1,200」「1200円」「１２００」などを整数にする。
     */
    public static function amount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        $text = self::text($value, 40);

        if ($text === null) {
            return null;
        }

        $normalized = mb_convert_kana($text, 'as');

        // 値引き行（`-100` `▲100` `△100`）の符号を落とさない。
        // 落とすと「牛乳1000＋会員値引100＝1100」なのに総額900、という食い違いになり、
        // 品目ごとの登録では値引きが +100円の支出として計上されてしまう
        $negative = (bool) preg_match('/^\s*[-−▲△]/u', $normalized);

        // 全角数字を半角にしてから数字だけ拾う
        $digits = (string) preg_replace('/[^0-9]/', '', $normalized);

        if ($digits === '') {
            return null;
        }

        return $negative ? -(int) $digits : (int) $digits;
    }

    /**
     * 日付を "Y-m-d" に正規化する。
     *
     * `<input type="date">` は Y-m-d 以外を無視して空欄になるので、
     * 「2026/08/01」「2026年8月1日」「20260801」もここで直す。
     *
     * Carbon::parse() には任せない。"2026" を今日の月日で補完したり、
     * "tomorrow" を実行日基準で解釈したりして、
     * 「もっともらしいが間違った日付」が確認画面に初期表示されてしまうため。
     * 読み取れない場合は null（確認画面では当日が初期値になる）。
     */
    public static function date(mixed $value): ?string
    {
        $text = self::text($value, 40);

        if ($text === null) {
            return null;
        }

        // 全角を半角にしてから区切り文字を「-」に寄せる
        $text = mb_convert_kana($text, 'as');
        $text = (string) preg_replace('/^(\d{4})年\s*(\d{1,2})月\s*(\d{1,2})日/u', '$1-$2-$3', $text);
        $text = str_replace(['/', '.'], '-', $text);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $matches)) {
            [, $year, $month, $day] = $matches;
        } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $text, $matches)) {
            [, $year, $month, $day] = $matches;
        } else {
            return null;
        }

        $year = (int) $year;
        $month = (int) $month;
        $day = (int) $day;

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        // 家計簿として現実的な範囲から外れたものは読み取れなかった扱いにする
        if ($year < MonthParser::MIN_YEAR || $year > (int) Carbon::now()->format('Y') + 1) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * 品目リストを {name, amount} の配列に揃える。想定外の形は捨てる。
     *
     * @return array<int, array{name: string, amount: int}>
     */
    public static function items(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = self::text($item['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'amount' => self::amount($item['amount'] ?? null) ?? 0,
            ];
        }

        return $normalized;
    }
}
