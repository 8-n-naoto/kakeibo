<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_予算一覧が表示できる(): void
    {
        Budget::factory()->create(['amount' => 250000]);

        $response = $this->get(route('budgets.index'));

        $response->assertOk();
        $response->assertSee('支出全体');
    }

    public function test_全体予算を登録できる(): void
    {
        $response = $this->post(route('budgets.store'), [
            'category_id' => null,
            'month' => null,
            'amount' => 250000,
        ]);

        $response->assertRedirect(route('budgets.index'));
        $this->assertDatabaseHas('budgets', ['category_id' => null, 'month' => null, 'amount' => 250000]);
    }

    public function test_カテゴリ別かつ月指定の予算を登録できる(): void
    {
        $category = Category::factory()->create(['name' => '食費']);

        $this->post(route('budgets.store'), [
            'category_id' => $category->id,
            'month' => '2026-08',
            'amount' => 50000,
        ])->assertRedirect(route('budgets.index'));

        $this->assertDatabaseHas('budgets', [
            'category_id' => $category->id,
            'month' => '2026-08-01',
            'amount' => 50000,
        ]);
    }

    public function test_同じ対象と月で登録し直すと上書きされる(): void
    {
        $payload = ['category_id' => null, 'month' => '2026-08', 'amount' => 200000];

        $this->post(route('budgets.store'), $payload);
        $this->post(route('budgets.store'), array_merge($payload, ['amount' => 300000]));

        $this->assertSame(1, Budget::count());
        $this->assertDatabaseHas('budgets', ['amount' => 300000]);
    }

    public function test_収入カテゴリは予算の対象にできない(): void
    {
        $income = Category::factory()->create(['name' => '給与', 'type' => 'income', 'expense_nature' => null]);

        $response = $this->from(route('budgets.create'))->post(route('budgets.store'), [
            'category_id' => $income->id,
            'amount' => 10000,
        ]);

        $response->assertSessionHasErrors('category_id');
    }

    public function test_予算を削除できる(): void
    {
        $budget = Budget::factory()->create();

        $this->delete(route('budgets.destroy', $budget))->assertRedirect(route('budgets.index'));

        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }

    public function test_月指定の予算がデフォルト予算より優先される(): void
    {
        $month = Carbon::create(2026, 8, 1);
        $category = Category::factory()->create(['name' => '食費']);

        Budget::factory()->create(['category_id' => $category->id, 'month' => null, 'amount' => 40000]);
        Budget::factory()->create(['category_id' => $category->id, 'month' => '2026-08-01', 'amount' => 60000]);

        Transaction::factory()->create([
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 30000,
            'transaction_date' => '2026-08-10',
        ]);

        $summary = app(BudgetService::class)->summary($month);
        $row = $summary['categories']->firstWhere('name', '食費');

        $this->assertSame(60000, $row['budget']);
        $this->assertSame(30000, $row['spent']);
        $this->assertSame(50.0, $row['usage_rate']);
        $this->assertSame(BudgetService::STATUS_OK, $row['status']);
    }

    public function test_消化率が80パーセント以上で要注意になる(): void
    {
        $month = Carbon::create(2026, 8, 1);

        Budget::factory()->create(['category_id' => null, 'month' => null, 'amount' => 100000]);
        Transaction::factory()->create(['type' => 'expense', 'amount' => 85000, 'transaction_date' => '2026-08-05']);

        $summary = app(BudgetService::class)->summary($month);

        $this->assertSame(85.0, $summary['overall']['usage_rate']);
        $this->assertSame(BudgetService::STATUS_WARNING, $summary['overall']['status']);
        $this->assertCount(1, $summary['alerts']);
    }

    public function test_予算を超えると超過と判定される(): void
    {
        $month = Carbon::create(2026, 8, 1);

        Budget::factory()->create(['category_id' => null, 'month' => null, 'amount' => 100000]);
        Transaction::factory()->create(['type' => 'expense', 'amount' => 120000, 'transaction_date' => '2026-08-20']);

        $summary = app(BudgetService::class)->summary($month);

        $this->assertSame(BudgetService::STATUS_OVER, $summary['overall']['status']);
        $this->assertSame(-20000, $summary['overall']['remaining']);
    }

    public function test_別の月の支出は消化率に含まれない(): void
    {
        Budget::factory()->create(['category_id' => null, 'month' => null, 'amount' => 100000]);
        Transaction::factory()->create(['type' => 'expense', 'amount' => 50000, 'transaction_date' => '2026-07-20']);

        $summary = app(BudgetService::class)->summary(Carbon::create(2026, 8, 1));

        $this->assertSame(0, $summary['overall']['spent']);
    }

    public function test_収入は予算の消化に含まれない(): void
    {
        Budget::factory()->create(['category_id' => null, 'month' => null, 'amount' => 100000]);
        Transaction::factory()->create(['type' => 'income', 'amount' => 300000, 'transaction_date' => '2026-08-25']);

        $summary = app(BudgetService::class)->summary(Carbon::create(2026, 8, 1));

        $this->assertSame(0, $summary['overall']['spent']);
    }

    public function test_ダッシュボードに予算超過アラートが表示される(): void
    {
        Budget::factory()->create(['category_id' => null, 'month' => null, 'amount' => 100000]);
        Transaction::factory()->create(['type' => 'expense', 'amount' => 150000, 'transaction_date' => '2026-08-15']);

        $response = $this->get(route('dashboard', ['month' => '2026-08']));

        $response->assertOk();
        $response->assertViewHas('budgetAlerts', fn ($alerts) => $alerts->count() === 1);
        $response->assertSee('予算超過');
    }

    public function test_予算未設定ならアラートは出ない(): void
    {
        Transaction::factory()->create(['type' => 'expense', 'amount' => 150000, 'transaction_date' => '2026-08-15']);

        $response = $this->get(route('dashboard', ['month' => '2026-08']));

        $response->assertOk();
        $response->assertViewHas('budgetAlerts', fn ($alerts) => $alerts->isEmpty());
    }
}
