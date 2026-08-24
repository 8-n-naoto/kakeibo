<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ダッシュボードのタブ分割。
 *
 * データは1リクエストで全部渡したうえで表示だけ出し分ける方針なので、
 * 「どのタブの中身もHTMLには含まれている」「初期表示は今月タブ」を確認する。
 */
class DashboardTabTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): void
    {
        $category = Category::factory()->create(['name' => '食費', 'expense_nature' => 'variable']);

        Transaction::factory()->create([
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 30000,
            'transaction_date' => '2026-08-10',
        ]);
    }

    public function test_3つのタブが表示される(): void
    {
        $response = $this->get(route('dashboard', ['month' => '2026-08']));

        $response->assertOk();
        $response->assertSee('data-tab="month"', false);
        $response->assertSee('data-tab="assets"', false);
        $response->assertSee('data-tab="review"', false);
    }

    public function test_今月タブは初期表示で開いている(): void
    {
        $response = $this->get(route('dashboard', ['month' => '2026-08']));

        $response->assertOk();
        // タグ全体で比べると、ARIA属性を足しただけで落ちる
        $response->assertSee('data-tab-panel="month" id="panel-month"', false);
        $response->assertSee('aria-labelledby="tab-assets" hidden', false);
        $response->assertSee('aria-labelledby="tab-review" hidden', false);
    }

    public function test_どのタブの中身も1回のリクエストで返る(): void
    {
        $this->seed();

        $response = $this->get(route('dashboard', ['month' => '2026-08']));

        $response->assertOk();
        // 今月タブ
        $response->assertSee('月別収支の推移(直近12ヶ月)');
        $response->assertSee('カテゴリ別支出(今月)');
        // 資産タブ
        $response->assertSee('総資産の推移(直近12ヶ月)');
        $response->assertSee('貯蓄目標');
        // 振り返りタブ
        $response->assertSee('固定費 / 変動費');
        $response->assertSee('前年同月比');
    }

    public function test_集計データはこれまでどおり渡される(): void
    {
        $this->seed();

        $response = $this->get(route('dashboard', ['month' => '2026-08']));

        $response->assertOk();
        foreach ([
            'income', 'expense', 'balance', 'byCategory', 'trend',
            'netWorthTrend', 'savingsGoals', 'investmentAccounts',
            'budgetSummary', 'fixedVariable', 'yearOverYear',
            'anomalies', 'pendingRecurrings',
        ] as $key) {
            $response->assertViewHas($key);
        }
    }
}
