<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionExportTest extends TestCase
{
    use RefreshDatabase;

    private function download(array $params = []): string
    {
        $response = $this->get(route('transactions.export', $params));
        $response->assertOk();

        return $response->streamedContent();
    }

    public function test_取引をCSVで出力できる(): void
    {
        $category = Category::factory()->create(['name' => '食費', 'expense_nature' => 'variable']);
        Transaction::factory()->create([
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'category_id' => $category->id,
            'shop_name' => 'スーパーA',
            'amount' => 3200,
        ]);

        $csv = $this->download();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('日付,種別,カテゴリ', $csv);
        $this->assertStringContainsString('2026-08-01,支出,食費,変動費,スーパーA,,3200', $csv);
    }

    public function test_月を指定して出力できる(): void
    {
        Transaction::factory()->create(['transaction_date' => '2026-08-01', 'shop_name' => '8月の店', 'amount' => 1000]);
        Transaction::factory()->create(['transaction_date' => '2026-07-01', 'shop_name' => '7月の店', 'amount' => 2000]);

        $csv = $this->download(['month' => '2026-08']);

        $this->assertStringContainsString('8月の店', $csv);
        $this->assertStringNotContainsString('7月の店', $csv);
    }

    public function test_カテゴリを指定して出力できる(): void
    {
        $target = Category::factory()->create(['name' => '対象']);
        Transaction::factory()->create(['category_id' => $target->id, 'shop_name' => '対象の店']);
        Transaction::factory()->create(['shop_name' => '対象外の店']);

        $csv = $this->download(['category_id' => $target->id]);

        $this->assertStringContainsString('対象の店', $csv);
        $this->assertStringNotContainsString('対象外の店', $csv);
    }

    public function test_ファイル名に対象月が入る(): void
    {
        $response = $this->get(route('transactions.export', ['month' => '2026-08']));

        $response->assertOk();
        $this->assertStringContainsString(
            'kakeibo_2026-08.csv',
            (string) $response->headers->get('content-disposition')
        );
    }
}
