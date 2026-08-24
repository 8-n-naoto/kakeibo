<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 月次予算の消化状況を集計し、超過アラートを判定するサービス。
 */
class BudgetService
{
    /** 警告を出す消化率(%) */
    public const WARNING_THRESHOLD = 80.0;

    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_OVER = 'over';

    public const STATUS_LABELS = [
        self::STATUS_OK => '順調',
        self::STATUS_WARNING => '要注意',
        self::STATUS_OVER => '予算超過',
    ];

    /**
     * 指定月の予算サマリー。
     *
     * @return array{month: Carbon, overall: ?array, categories: Collection, alerts: Collection}
     */
    public function summary(Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();

        $budgets = Budget::with('category')->applicableTo($month)->get();
        $spentByCategory = $this->spentByCategory($month);
        $totalSpent = (int) $spentByCategory->sum();

        $overallBudget = $this->resolveAmount($budgets, null, $month);
        $overall = $overallBudget === null
            ? null
            : $this->buildRow(null, $overallBudget, $totalSpent);

        $categories = Category::expense()->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (Category $category) use ($budgets, $spentByCategory, $month): ?array {
                $amount = $this->resolveAmount($budgets, $category->id, $month);

                if ($amount === null) {
                    return null;
                }

                return $this->buildRow($category, $amount, (int) ($spentByCategory[$category->id] ?? 0));
            })
            ->filter()
            ->values();

        $alerts = collect($overall ? [$overall] : [])
            ->concat($categories)
            ->filter(fn (array $row) => $row['status'] !== self::STATUS_OK)
            ->sortByDesc('usage_rate')
            ->values();

        return [
            'month' => $month,
            'overall' => $overall,
            'categories' => $categories,
            'alerts' => $alerts,
        ];
    }

    /**
     * その月に実際に適用される予算額を返す。
     * キーは category_id、支出全体は 'overall'。設定が無いカテゴリは含めない。
     *
     * 「月指定 > デフォルト」の優先順位を1か所に閉じ込めるための入口。
     * 予算提案など、summary() ほど重くない用途から使う。
     *
     * @return array<int|string, int>
     */
    public function appliedAmounts(Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();
        $budgets = Budget::applicableTo($month)->get();

        $amounts = [];

        $overall = $this->resolveAmount($budgets, null, $month);

        if ($overall !== null) {
            $amounts['overall'] = $overall;
        }

        foreach ($budgets->pluck('category_id')->filter()->unique() as $categoryId) {
            $amount = $this->resolveAmount($budgets, (int) $categoryId, $month);

            if ($amount !== null) {
                $amounts[(int) $categoryId] = $amount;
            }
        }

        return $amounts;
    }

    /**
     * 消化率から状態を判定する。
     */
    public static function statusOf(float $usageRate): string
    {
        if ($usageRate > 100.0) {
            return self::STATUS_OVER;
        }

        if ($usageRate >= self::WARNING_THRESHOLD) {
            return self::STATUS_WARNING;
        }

        return self::STATUS_OK;
    }

    /**
     * カテゴリ別の当月支出額(category_id => 金額)。
     */
    private function spentByCategory(Carbon $month): Collection
    {
        return Transaction::query()
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id')
            ->map(fn ($total) => (int) $total);
    }

    /**
     * その月に適用される予算額。月指定の予算 > デフォルト予算 の順で採用。無ければ null。
     */
    private function resolveAmount(Collection $budgets, ?int $categoryId, Carbon $month): ?int
    {
        $target = $month->copy()->startOfMonth()->toDateString();

        $scoped = $budgets->filter(fn (Budget $b) => $b->category_id === $categoryId);

        $specific = $scoped->first(fn (Budget $b) => $b->month?->toDateString() === $target);

        if ($specific) {
            return $specific->amount;
        }

        return $scoped->first(fn (Budget $b) => $b->month === null)?->amount;
    }

    /**
     * @return array{category: ?Category, name: string, budget: int, spent: int, remaining: int, usage_rate: float, status: string}
     */
    private function buildRow(?Category $category, int $budget, int $spent): array
    {
        // 予算0円のとき、消化率0%＝「順調」にしてしまうと、
        // 使っているのにアラートが出ない（予算を設定していない状態より悪い）
        $usageRate = match (true) {
            $budget > 0 => round(($spent / $budget) * 100, 1),
            $spent > 0 => 100.1,
            default => 0.0,
        };

        return [
            'category' => $category,
            'name' => $category?->name ?? '支出全体',
            'budget' => $budget,
            'spent' => $spent,
            'remaining' => $budget - $spent,
            'usage_rate' => $usageRate,
            'status' => self::statusOf($usageRate),
        ];
    }
}
