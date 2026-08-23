<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 月次の集計(固定費/変動費の分離、前年同月比)を行うサービス。
 */
class MonthlyReportService
{
    /**
     * 固定費 / 変動費の内訳。カテゴリ未設定の支出は変動費として扱う。
     *
     * @return array{fixed: int, variable: int, total: int, fixed_rate: ?float, fixed_categories: Collection}
     */
    public function fixedVariableBreakdown(Carbon $month): array
    {
        $transactions = $this->expenseTransactions($month);

        $fixed = 0;
        $variable = 0;
        $fixedCategories = [];

        foreach ($transactions as $transaction) {
            $isFixed = (bool) $transaction->category?->isFixedCost();

            if ($isFixed) {
                $fixed += (int) $transaction->amount;
                $name = $transaction->category->name;
                $fixedCategories[$name] = ($fixedCategories[$name] ?? 0) + (int) $transaction->amount;
            } else {
                $variable += (int) $transaction->amount;
            }
        }

        $total = $fixed + $variable;

        return [
            'fixed' => $fixed,
            'variable' => $variable,
            'total' => $total,
            'fixed_rate' => $total > 0 ? round(($fixed / $total) * 100, 1) : null,
            'fixed_categories' => collect($fixedCategories)->sortDesc(),
        ];
    }

    /**
     * 前年同月との比較。
     *
     * @return array{
     *   previous_month: Carbon,
     *   income: array{current: int, previous: int, diff: int, rate: ?float},
     *   expense: array{current: int, previous: int, diff: int, rate: ?float},
     *   categories: Collection,
     * }
     */
    public function yearOverYear(Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();
        $previousMonth = $month->copy()->subYear();

        $current = $this->totalsFor($month);
        $previous = $this->totalsFor($previousMonth);

        $currentByCategory = $this->expenseByCategory($month);
        $previousByCategory = $this->expenseByCategory($previousMonth);

        $categories = collect($currentByCategory->keys())
            ->merge($previousByCategory->keys())
            ->unique()
            ->map(function (string $name) use ($currentByCategory, $previousByCategory): array {
                $currentAmount = (int) ($currentByCategory[$name] ?? 0);
                $previousAmount = (int) ($previousByCategory[$name] ?? 0);

                return [
                    'name' => $name,
                    'current' => $currentAmount,
                    'previous' => $previousAmount,
                    'diff' => $currentAmount - $previousAmount,
                    'rate' => $this->changeRate($currentAmount, $previousAmount),
                ];
            })
            ->sortByDesc(fn (array $row) => abs($row['diff']))
            ->values();

        return [
            'previous_month' => $previousMonth,
            'income' => $this->comparison($current['income'], $previous['income']),
            'expense' => $this->comparison($current['expense'], $previous['expense']),
            'categories' => $categories,
        ];
    }

    /**
     * @return array{income: int, expense: int}
     */
    private function totalsFor(Carbon $month): array
    {
        $totals = Transaction::query()
            ->whereBetween('transaction_date', $this->range($month))
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'income' => (int) ($totals['income'] ?? 0),
            'expense' => (int) ($totals['expense'] ?? 0),
        ];
    }

    private function expenseByCategory(Carbon $month): Collection
    {
        return $this->expenseTransactions($month)
            ->groupBy(fn (Transaction $t) => $t->category?->name ?? '未分類')
            ->map(fn (Collection $items) => (int) $items->sum('amount'));
    }

    private function expenseTransactions(Carbon $month): Collection
    {
        return Transaction::with('category')
            ->where('type', 'expense')
            ->whereBetween('transaction_date', $this->range($month))
            ->get();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(Carbon $month): array
    {
        return [
            $month->copy()->startOfMonth()->toDateString(),
            $month->copy()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * @return array{current: int, previous: int, diff: int, rate: ?float}
     */
    private function comparison(int $current, int $previous): array
    {
        return [
            'current' => $current,
            'previous' => $previous,
            'diff' => $current - $previous,
            'rate' => $this->changeRate($current, $previous),
        ];
    }

    /**
     * 増減率(%)。前年が0の場合は比較不能なので null。
     */
    private function changeRate(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * 未分類を含む支出カテゴリの一覧(表示用)。
     */
    public function expenseCategories(): Collection
    {
        return Category::expense()->orderBy('sort_order')->orderBy('id')->get();
    }
}
