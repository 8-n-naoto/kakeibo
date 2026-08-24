<?php

namespace Tests\Unit;

use App\Support\MonthParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `YYYY-MM` の解析。
 *
 * Carbon::createFromFormat('!Y-m', ...) は範囲外でも例外を投げず、
 * "2026-13" を 2027-01 に、"9999-99" を 10007-03 にしてしまう。
 * 定期支出の計上（＝書き込み）にも使う値なので、ここで確実に弾く。
 */
class MonthParserTest extends TestCase
{
    public static function invalidProvider(): array
    {
        $tooFar = ((int) date('Y') + MonthParser::MAX_YEARS_AHEAD + 1).'-01';

        return [
            '13月' => ['2026-13'],
            '0月' => ['2026-00'],
            '桁あふれ' => ['9999-99'],
            '月が1桁' => ['2026-8'],
            '前に空白' => [' 2026-08'],
            '後ろに空白' => ['2026-08 '],
            '年が5桁' => ['12026-08'],
            '古すぎる年' => ['1999-12'],
            '未来すぎる年' => [$tooFar],
            '日本語' => ['にせんにじゅうろく'],
            '空文字' => [''],
            'null' => [null],
            '配列' => [['2026-08']],
            '整数' => [202608],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_不正な月はnullを返す(mixed $input): void
    {
        $this->assertNull(MonthParser::parse($input));
    }

    public static function validProvider(): array
    {
        return [
            '通常' => ['2026-08', '2026-08-01'],
            '1月' => ['2026-01', '2026-01-01'],
            '12月' => ['2026-12', '2026-12-01'],
            '下限の年' => [MonthParser::MIN_YEAR.'-01', MonthParser::MIN_YEAR.'-01-01'],
        ];
    }

    #[DataProvider('validProvider')]
    public function test_妥当な月は月初のCarbonを返す(string $input, string $expected): void
    {
        $this->assertSame($expected, MonthParser::parse($input)?->toDateString());
    }

    public function test_上限の年は受け付ける(): void
    {
        $limit = ((int) date('Y') + MonthParser::MAX_YEARS_AHEAD).'-12';

        $this->assertNotNull(MonthParser::parse($limit));
    }

    public function test_不正な月は当月に落とす(): void
    {
        $this->assertSame(date('Y-m').'-01', MonthParser::parseOrCurrent('2026-13')->toDateString());
        $this->assertSame(date('Y-m').'-01', MonthParser::parseOrCurrent(null)->toDateString());
    }
}
