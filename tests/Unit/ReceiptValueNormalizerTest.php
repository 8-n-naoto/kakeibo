<?php

namespace Tests\Unit;

use App\Support\ReceiptValueNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AI が返した値の正規化。
 *
 * AI は型も表記も保証してくれないので、ここが崩れると
 * 「金額が3円になる」「1件のせいで一覧が開けない」といった事故になる。
 */
class ReceiptValueNormalizerTest extends TestCase
{
    public static function textProvider(): array
    {
        return [
            '前後の空白は落とす' => ['  スーパーライフ  ', 'スーパーライフ'],
            '数値も文字列にする' => [123, '123'],
            '空文字はnull' => ['', null],
            '空白だけもnull' => ['   ', null],
            'nullはnull' => [null, null],
            '配列は捨てる' => [['name' => 'x'], null],
            '真偽値は文字列になる' => [true, '1'],
        ];
    }

    #[DataProvider('textProvider')]
    public function test_文字列項目の正規化(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, ReceiptValueNormalizer::text($input));
    }

    public function test_文字列は255文字で切る(): void
    {
        $this->assertSame(255, mb_strlen((string) ReceiptValueNormalizer::text(str_repeat('あ', 300))));
    }

    public static function amountProvider(): array
    {
        return [
            '整数はそのまま' => [3200, 3200],
            'カンマ区切り' => ['3,200', 3200],
            '通貨記号つき' => ['¥3,200', 3200],
            '単位つき' => ['1200円', 1200],
            '全角数字' => ['１２００', 1200],
            '小数は四捨五入' => [3200.6, 3201],
            'ゼロ' => ['0', 0],
            '数字が無ければnull' => ['—', null],
            '空はnull' => ['', null],
            'nullはnull' => [null, null],
            '配列はnull' => [[100], null],
            // 値引き行の符号を落とすと、品目の合計が総額と合わなくなる
            'マイナス' => ['-100', -100],
            '全角マイナス' => ['－100', -100],
            '黒三角' => ['▲100', -100],
            '白三角' => ['△100', -100],
            '整数のマイナス' => [-100, -100],
            '途中のハイフンは符号ではない' => ['03-1234', 31234],
        ];
    }

    #[DataProvider('amountProvider')]
    public function test_金額の正規化(mixed $input, ?int $expected): void
    {
        $this->assertSame($expected, ReceiptValueNormalizer::amount($input));
    }

    public static function dateProvider(): array
    {
        $nextYear = (int) date('Y') + 1;

        return [
            'ハイフン区切り' => ['2026-08-01', '2026-08-01'],
            'スラッシュ区切り' => ['2026/08/01', '2026-08-01'],
            'ドット区切り' => ['2026.8.1', '2026-08-01'],
            '和暦表記なしの日本語' => ['2026年8月1日', '2026-08-01'],
            '全角の日本語' => ['２０２６年８月１日', '2026-08-01'],
            '区切りなし8桁' => ['20260801', '2026-08-01'],
            '時刻つき' => ['2026-08-01T10:30:00', '2026-08-01'],
            // Carbon::parse に任せると今日の月日で補完されてしまうケース
            '年だけはnull' => ['2026', null],
            '相対表現はnull' => ['tomorrow', null],
            '現在時刻もnull' => ['now', null],
            '存在しない日付' => ['2026-02-30', null],
            '存在しない月' => ['2026-13-01', null],
            '古すぎる日付' => ['1800-01-01', null],
            '未来すぎる日付' => [($nextYear + 5).'-01-01', null],
            '日本語はnull' => ['不明', null],
            '配列はnull' => [['2026-08-01'], null],
            'nullはnull' => [null, null],
        ];
    }

    #[DataProvider('dateProvider')]
    public function test_日付の正規化(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, ReceiptValueNormalizer::date($input));
    }

    public function test_来年までは受け付ける(): void
    {
        $nextYear = (int) date('Y') + 1;

        $this->assertSame($nextYear.'-01-01', ReceiptValueNormalizer::date($nextYear.'-01-01'));
    }

    public function test_品目リストの正規化(): void
    {
        $items = ReceiptValueNormalizer::items([
            ['name' => '牛乳', 'amount' => 200],
            ['name' => 'パン', 'amount' => '1,200'],
            ['name' => '水'],                       // 金額なし → 0
            ['name' => ['配列'], 'amount' => 100],  // 名前が配列 → 捨てる
            ['amount' => 500],                      // 名前なし → 捨てる
            ['name' => '', 'amount' => 500],        // 空の名前 → 捨てる
            '文字列の要素',                          // 要素が配列でない → 捨てる
        ]);

        $this->assertSame([
            ['name' => '牛乳', 'amount' => 200],
            ['name' => 'パン', 'amount' => 1200],
            ['name' => '水', 'amount' => 0],
        ], $items);
    }

    public static function invalidItemsProvider(): array
    {
        return [
            '文字列' => ['牛乳 200円'],
            'null' => [null],
            '整数' => [123],
        ];
    }

    #[DataProvider('invalidItemsProvider')]
    public function test_品目が配列でなければ空配列(mixed $input): void
    {
        $this->assertSame([], ReceiptValueNormalizer::items($input));
    }
}
