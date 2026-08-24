<?php

namespace Tests\Feature;

use App\Models\AssetSnapshot;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\AnnualReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 年間サマリーレポート。
 */
class AnnualReportTest extends TestCase
{
    use RefreshDatabase;

    private function seedTwoYears(): array
    {
        $food = Category::factory()->create(['name' => '食費', 'expense_nature' => 'variable', 'counts_as_food' => true]);
        $rent = Category::factory()->create(['name' => '住居費', 'expense_nature' => 'fixed']);
        $salary = Category::factory()->create(['name' => '給与', 'type' => 'income']);

        // 2026年
        Transaction::factory()->create(['transaction_date' => '2026-01-15', 'type' => 'income', 'category_id' => $salary->id, 'amount' => 300000]);
        Transaction::factory()->create(['transaction_date' => '2026-02-15', 'type' => 'income', 'category_id' => $salary->id, 'amount' => 300000]);
        Transaction::factory()->create(['transaction_date' => '2026-01-20', 'type' => 'expense', 'category_id' => $food->id, 'amount' => 40000]);
        Transaction::factory()->create(['transaction_date' => '2026-02-20', 'type' => 'expense', 'category_id' => $food->id, 'amount' => 60000]);
        Transaction::factory()->create(['transaction_date' => '2026-01-27', 'type' => 'expense', 'category_id' => $rent->id, 'amount' => 100000]);

        // 2025年（前年比の比較用）
        Transaction::factory()->create(['transaction_date' => '2025-01-20', 'type' => 'expense', 'category_id' => $food->id, 'amount' => 50000]);
        Transaction::factory()->create(['transaction_date' => '2025-01-15', 'type' => 'income', 'category_id' => $salary->id, 'amount' => 280000]);

        return ['food' => $food, 'rent' => $rent, 'salary' => $salary];
    }

    public function test_年間の収支が集計される(): void
    {
        $this->seedTwoYears();

        $summary = app(AnnualReportService::class)->summary(2026);

        $this->assertSame(600000, $summary['income']);
        $this->assertSame(200000, $summary['expense']);
        $this->assertSame(400000, $summary['balance']);
        $this->assertSame(5, $summary['transaction_count']);
    }

    public function test_月別の内訳が12ヶ月分そろう(): void
    {
        $this->seedTwoYears();

        $summary = app(AnnualReportService::class)->summary(2026);

        $this->assertCount(12, $summary['months']);
        $this->assertSame(300000, $summary['months'][0]['income']);
        $this->assertSame(140000, $summary['months'][0]['expense']);
        $this->assertSame(0, $summary['months'][11]['expense']);
    }

    public function test_月平均は記録のある月だけで計算される(): void
    {
        $this->seedTwoYears();

        $summary = app(AnnualReportService::class)->summary(2026);

        // 記録があるのは1月と2月の2ヶ月
        $this->assertSame(2, $summary['active_months']);
        $this->assertSame(100000, $summary['monthly_average']['expense']);
    }

    public function test_カテゴリ別に前年比が出る(): void
    {
        $this->seedTwoYears();

        $summary = app(AnnualReportService::class)->summary(2026);
        $food = $summary['by_category']->firstWhere('name', '食費');

        $this->assertSame(100000, $food['amount']);
        $this->assertSame(50000, $food['previous']);
        $this->assertSame(50000, $food['diff']);
        $this->assertSame(100.0, $food['rate']);
        $this->assertSame(50.0, $food['share']);
    }

    public function test_固定費率が計算される(): void
    {
        $this->seedTwoYears();

        $summary = app(AnnualReportService::class)->summary(2026);

        $this->assertSame(100000, $summary['fixed_variable']['fixed']);
        $this->assertSame(100000, $summary['fixed_variable']['variable']);
        $this->assertSame(50.0, $summary['fixed_variable']['fixed_rate']);
    }

    public function test_年間エンゲル係数が出る(): void
    {
        $this->seedTwoYears();

        $summary = app(AnnualReportService::class)->summary(2026);

        // 食費 100,000 / 支出 200,000
        $this->assertSame(50.0, $summary['engel_coefficient']);
    }

    public function test_総資産の増減が出る(): void
    {
        $this->seedTwoYears();

        AssetSnapshot::create(['snapshot_date' => '2026-01-31', 'cash_amount' => 1000000, 'nisa_amount' => 0, 'ideco_amount' => 0, 'other_investment_amount' => 0]);
        AssetSnapshot::create(['snapshot_date' => '2026-12-31', 'cash_amount' => 1500000, 'nisa_amount' => 0, 'ideco_amount' => 0, 'other_investment_amount' => 0]);

        $summary = app(AnnualReportService::class)->summary(2026);

        $this->assertSame(500000, $summary['assets']['change']);
    }

    public function test_スナップショットが1件だけなら増減は出さない(): void
    {
        AssetSnapshot::create(['snapshot_date' => '2026-01-31', 'cash_amount' => 1000000, 'nisa_amount' => 0, 'ideco_amount' => 0, 'other_investment_amount' => 0]);

        $summary = app(AnnualReportService::class)->summary(2026);

        $this->assertNull($summary['assets']['change']);
    }

    public function test_レポート画面が表示できる(): void
    {
        $this->seedTwoYears();

        $response = $this->get(route('reports.annual', ['year' => 2026]));

        $response->assertOk();
        $response->assertSee('2026年の家計まとめ');
        $response->assertSee('食費');
        $response->assertSee('600,000');
    }

    public function test_取引が無い年は案内を出す(): void
    {
        $response = $this->get(route('reports.annual', ['year' => 2020]));

        $response->assertOk();
        $response->assertSee('2020年の取引はまだありません');
    }

    public function test_クエリ文字列でも年を切り替えられる(): void
    {
        $this->seedTwoYears();

        $response = $this->get(route('reports.annual', ['year' => 2025]));

        $response->assertOk();
        $response->assertViewHas('summary', fn (array $summary) => $summary['year'] === 2025 && $summary['expense'] === 50000);
    }

    public function test_ありえない年は今年に寄せる(): void
    {
        $response = $this->get('/reports/1800');

        $response->assertOk();
        $response->assertViewHas('summary', fn (array $summary) => $summary['year'] === (int) now()->format('Y'));
    }

    public function test_年の選択肢に取引のある年が並ぶ(): void
    {
        $this->seedTwoYears();

        $years = app(AnnualReportService::class)->availableYears();

        $this->assertContains(2026, $years);
        $this->assertContains(2025, $years);
    }
}
