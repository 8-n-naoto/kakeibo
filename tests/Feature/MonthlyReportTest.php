<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\MonthlyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MonthlyReportService
    {
        return app(MonthlyReportService::class);
    }

    public function test_固定費と変動費が分離して集計される(): void
    {
        $rent = Category::factory()->create(['name' => '住居費', 'expense_nature' => 'fixed']);
        $food = Category::factory()->create(['name' => '食費', 'expense_nature' => 'variable']);

        Transaction::factory()->create(['category_id' => $rent->id, 'type' => 'expense', 'amount' => 80000, 'transaction_date' => '2026-08-01']);
        Transaction::factory()->create(['category_id' => $food->id, 'type' => 'expense', 'amount' => 20000, 'transaction_date' => '2026-08-05']);

        $result = $this->service()->fixedVariableBreakdown(Carbon::create(2026, 8, 1));

        $this->assertSame(80000, $result['fixed']);
        $this->assertSame(20000, $result['variable']);
        $this->assertSame(100000, $result['total']);
        $this->assertSame(80.0, $result['fixed_rate']);
    }

    public function test_カテゴリ未設定の支出は変動費として扱われる(): void
    {
        Transaction::factory()->create(['category_id' => null, 'type' => 'expense', 'amount' => 5000, 'transaction_date' => '2026-08-03']);

        $result = $this->service()->fixedVariableBreakdown(Carbon::create(2026, 8, 1));

        $this->assertSame(0, $result['fixed']);
        $this->assertSame(5000, $result['variable']);
    }

    public function test_収入は固定費変動費の集計に含まれない(): void
    {
        Transaction::factory()->create(['type' => 'income', 'amount' => 300000, 'transaction_date' => '2026-08-25']);

        $result = $this->service()->fixedVariableBreakdown(Carbon::create(2026, 8, 1));

        $this->assertSame(0, $result['total']);
        $this->assertNull($result['fixed_rate']);
    }

    public function test_前年同月比が計算される(): void
    {
        Transaction::factory()->create(['type' => 'expense', 'amount' => 120000, 'transaction_date' => '2026-08-10']);
        Transaction::factory()->create(['type' => 'expense', 'amount' => 100000, 'transaction_date' => '2025-08-10']);
        Transaction::factory()->create(['type' => 'income', 'amount' => 300000, 'transaction_date' => '2026-08-25']);
        Transaction::factory()->create(['type' => 'income', 'amount' => 250000, 'transaction_date' => '2025-08-25']);

        $result = $this->service()->yearOverYear(Carbon::create(2026, 8, 1));

        $this->assertSame('2025-08', $result['previous_month']->format('Y-m'));
        $this->assertSame(120000, $result['expense']['current']);
        $this->assertSame(100000, $result['expense']['previous']);
        $this->assertSame(20000, $result['expense']['diff']);
        $this->assertSame(20.0, $result['expense']['rate']);
        $this->assertSame(50000, $result['income']['diff']);
        $this->assertSame(20.0, $result['income']['rate']);
    }

    public function test_前年に実績が無い場合は増減率がnullになる(): void
    {
        Transaction::factory()->create(['type' => 'expense', 'amount' => 50000, 'transaction_date' => '2026-08-10']);

        $result = $this->service()->yearOverYear(Carbon::create(2026, 8, 1));

        $this->assertSame(50000, $result['expense']['diff']);
        $this->assertNull($result['expense']['rate']);
    }

    public function test_前年同月比はカテゴリ別にも比較できる(): void
    {
        $food = Category::factory()->create(['name' => '食費']);

        Transaction::factory()->create(['category_id' => $food->id, 'type' => 'expense', 'amount' => 60000, 'transaction_date' => '2026-08-10']);
        Transaction::factory()->create(['category_id' => $food->id, 'type' => 'expense', 'amount' => 40000, 'transaction_date' => '2025-08-10']);

        $result = $this->service()->yearOverYear(Carbon::create(2026, 8, 1));
        $row = $result['categories']->firstWhere('name', '食費');

        $this->assertSame(60000, $row['current']);
        $this->assertSame(40000, $row['previous']);
        $this->assertSame(20000, $row['diff']);
        $this->assertSame(50.0, $row['rate']);
    }

    public function test_ダッシュボードに固定費率と前年同月比が渡される(): void
    {
        $rent = Category::factory()->create(['name' => '住居費', 'expense_nature' => 'fixed']);
        Transaction::factory()->create(['category_id' => $rent->id, 'type' => 'expense', 'amount' => 80000, 'transaction_date' => '2026-08-01']);

        $response = $this->get(route('dashboard', ['month' => '2026-08']));

        $response->assertOk();
        $response->assertViewHas('fixedVariable', fn (array $data) => $data['fixed'] === 80000 && $data['fixed_rate'] === 100.0);
        $response->assertViewHas('yearOverYear', fn (array $data) => $data['previous_month']->format('Y-m') === '2025-08');
    }
}
