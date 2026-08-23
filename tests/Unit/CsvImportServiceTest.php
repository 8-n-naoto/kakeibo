<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Services\CsvImportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvImportServiceTest extends TestCase
{
    private CsvImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CsvImportService();
    }

    public static function dateProvider(): array
    {
        return [
            'スラッシュ区切り' => ['2026/08/01', '2026-08-01'],
            'ハイフン区切り' => ['2026-08-01', '2026-08-01'],
            'ドット区切り' => ['2026.8.1', '2026-08-01'],
            '和暦表記なしの日本語' => ['2026年8月1日', '2026-08-01'],
            '区切りなし8桁' => ['20260801', '2026-08-01'],
            '前後の空白' => ['  2026/08/01  ', '2026-08-01'],
            '存在しない日付' => ['2026/02/30', null],
            '空文字' => ['', null],
            '数値でない' => ['未確定', null],
        ];
    }

    #[DataProvider('dateProvider')]
    public function test_日付を解析できる(string $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->service->parseDate($input));
    }

    public static function amountProvider(): array
    {
        return [
            '素の数値' => ['3200', 3200],
            'カンマ区切り' => ['1,234', 1234],
            '円記号つき' => ['¥1,234', 1234],
            '円つき' => ['1,234円', 1234],
            'マイナス' => ['-1500', -1500],
            '三角マーク' => ['△1,500', -1500],
            '空文字' => ['', null],
            '数値なし' => ['---', null],
        ];
    }

    #[DataProvider('amountProvider')]
    public function test_金額を解析できる(string $input, ?int $expected): void
    {
        $this->assertSame($expected, $this->service->parseAmount($input));
    }

    public function test_店名からカテゴリを推測できる(): void
    {
        $categories = collect([
            $this->category(1, '交通費'),
            $this->category(2, '食費'),
            $this->category(3, '娯楽・趣味'),
        ]);

        $this->assertSame(1, $this->service->guessCategoryId('JR東日本 新宿駅', $categories));
        $this->assertSame(2, $this->service->guessCategoryId('イオンモール', $categories));
        $this->assertSame(3, $this->service->guessCategoryId('AMAZON.CO.JP', $categories));
    }

    public function test_該当キーワードが無ければnullを返す(): void
    {
        $categories = collect([$this->category(1, '交通費')]);

        $this->assertNull($this->service->guessCategoryId('謎の店', $categories));
        $this->assertNull($this->service->guessCategoryId(null, $categories));
    }

    private function category(int $id, string $name): Category
    {
        $category = new Category(['name' => $name, 'type' => 'expense']);
        $category->forceFill(['id' => $id]);

        return $category;
    }
}
