<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * クエリ文字列やコマンド引数の `YYYY-MM` を Carbon に変換する。
 *
 * 注意点が2つある。
 *
 * 1. `?month[]=x` のように配列を送られても落ちないよう、型を絞る前に is_string で確認する。
 *    型付き引数に直接渡すと、引数バインドの時点で TypeError になり try/catch では拾えない。
 * 2. `Carbon::createFromFormat('!Y-m', ...)` は範囲外の月でも例外を投げない。
 *    "2026-13" は 2027-01 に、"9999-99" は 10007-03 になってしまう。
 *    定期支出の計上のような書き込み処理にも使うので、正規表現で形と範囲を先に固める。
 */
final class MonthParser
{
    /** 受け付ける年の下限（家計簿として現実的な範囲に絞る） */
    public const MIN_YEAR = 2000;

    /** 受け付ける年の上限（今年から何年先まで許すか） */
    public const MAX_YEARS_AHEAD = 5;

    /**
     * 妥当な `YYYY-MM` のときだけ Carbon を返す。それ以外は null。
     */
    public static function parse(mixed $month): ?Carbon
    {
        if (! is_string($month) || ! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $matches)) {
            return null;
        }

        $year = (int) $matches[1];

        if ($year < self::MIN_YEAR || $year > (int) Carbon::now()->format('Y') + self::MAX_YEARS_AHEAD) {
            return null;
        }

        return Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
    }

    /**
     * 妥当でなければ当月を返す。
     */
    public static function parseOrCurrent(mixed $month): Carbon
    {
        return self::parse($month) ?? Carbon::now()->startOfMonth();
    }
}
