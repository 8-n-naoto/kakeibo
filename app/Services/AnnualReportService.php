<?php

namespace App\Services;

use App\Http\Controllers\AnnualReportController;
use App\Models\AssetSnapshot;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\MonthParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 1年分の家計をまとめて振り返るためのサービス。
 *
 * 1年分の取引はせいぜい数千件なので、まとめて取得してPHP側で集計する。
 * （月ごとにクエリを投げると12回×2年分になるため）
 */
class AnnualReportService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(int $year): array
    {
        $current = $this->transactionsOf($year);
        $previous = $this->transactionsOf($year - 1);

        $income = (int) $current->where('type', 'income')->sum('amount');
        $expense = (int) $current->where('type', 'expense')->sum('amount');

        $months = $this->monthlyBreakdown($year, $current);
        $activeMonths = collect($months)->filter(fn (array $m) => $m['income'] > 0 || $m['expense'] > 0)->count();

        $byCategory = $this->categoryBreakdown($current, $previous, $expense, $activeMonths);

        return [
            'year' => $year,
            'income' => $income,
            'expense' => $expense,
            'balance' => $income - $expense,
            'transaction_count' => $current->count(),
            'months' => $months,
            'active_months' => $activeMonths,
            'monthly_average' => [
                'income' => $activeMonths > 0 ? (int) round($income / $activeMonths) : 0,
                'expense' => $activeMonths > 0 ? (int) round($expense / $activeMonths) : 0,
            ],
            'best_month' => collect($months)->sortByDesc('balance')->first(),
            'worst_month' => collect($months)->sortBy('balance')->first(),
            'by_category' => $byCategory,
            'fixed_variable' => $this->fixedVariable($current),
            'previous' => [
                'income' => (int) $previous->where('type', 'income')->sum('amount'),
                'expense' => (int) $previous->where('type', 'expense')->sum('amount'),
            ],
            'engel_coefficient' => $this->engelCoefficient($current, $expense),
            'assets' => $this->assets($year),
        ];
    }

    /**
     * 取引のある年の一覧（レポートの年セレクタ用）。
     *
     * @return array<int, int>
     */
    public function availableYears(): array
    {
        // 全行を読むと件数が増えたときに重いので、最古と最新だけ取って範囲を作る
        $range = Transaction::query()
            ->selectRaw('MIN(transaction_date) as first_date, MAX(transaction_date) as last_date')
            ->first();

        $thisYear = (int) Carbon::now()->format('Y');

        if (! $range || ! $range->first_date) {
            return [$thisYear];
        }

        // 打ち間違いで極端に古い日付が1件混ざっても、選べない年が大量に並ばないようにする
        // （AnnualReportController::resolveYear() の下限と揃える）
        $from = max((int) Carbon::parse($range->first_date)->format('Y'), MonthParser::MIN_YEAR);
        // 「選べるのに開くと今年に戻る年」が並ばないよう、上限もコントローラと揃える
        $to = min(
            max((int) Carbon::parse($range->last_date)->format('Y'), $thisYear),
            $thisYear + AnnualReportController::MAX_YEARS_AHEAD,
        );

        if ($from > $to) {
            return [$thisYear];
        }

        return array_reverse(range($from, $to));
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function transactionsOf(int $year): Collection
    {
        return Transaction::with('category')
            ->whereBetween('transaction_date', [
                Carbon::create($year, 1, 1)->toDateString(),
                Carbon::create($year, 12, 31)->toDateString(),
            ])
            ->get();
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, array{label: string, month: int, income: int, expense: int, balance: int}>
     */
    private function monthlyBreakdown(int $year, Collection $transactions): array
    {
        $grouped = $transactions->groupBy(fn (Transaction $t) => (int) $t->transaction_date->format('n'));

        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $rows = $grouped->get($month, collect());
            $income = (int) $rows->where('type', 'income')->sum('amount');
            $expense = (int) $rows->where('type', 'expense')->sum('amount');

            $months[] = [
                'label' => $month.'月',
                'month' => $month,
                'income' => $income,
                'expense' => $expense,
                'balance' => $income - $expense,
            ];
        }

        return $months;
    }

    /**
     * カテゴリ別の年間支出と、前年との比較。
     *
     * @param  Collection<int, Transaction>  $current
     * @param  Collection<int, Transaction>  $previous
     * @return Collection<int, array<string, mixed>>
     */
    private function categoryBreakdown(Collection $current, Collection $previous, int $totalExpense, int $activeMonths): Collection
    {
        $sumByName = fn (Collection $rows) => $rows
            ->where('type', 'expense')
            ->groupBy(fn (Transaction $t) => $t->category?->name ?? '未分類')
            ->map(fn (Collection $items) => (int) $items->sum('amount'));

        $currentByName = $sumByName($current);
        $previousByName = $sumByName($previous);

        $colors = $current->where('type', 'expense')
            ->groupBy(fn (Transaction $t) => $t->category?->name ?? '未分類')
            ->map(fn (Collection $items) => $items->first()->category?->color ?? '#94a3b8');

        return $currentByName->keys()
            ->merge($previousByName->keys())
            ->unique()
            ->map(function (string $name) use ($currentByName, $previousByName, $colors, $totalExpense, $activeMonths): array {
                $amount = (int) ($currentByName[$name] ?? 0);
                $previousAmount = (int) ($previousByName[$name] ?? 0);

                return [
                    'name' => $name,
                    'color' => $colors[$name] ?? '#94a3b8',
                    'amount' => $amount,
                    // ヘッダーの月平均と同じ「記録のある月数」で割る（進行中の年でも実感に合う）
                    'monthly_average' => $activeMonths > 0 ? (int) round($amount / $activeMonths) : 0,
                    'share' => $totalExpense > 0 ? round(($amount / $totalExpense) * 100, 1) : 0.0,
                    'previous' => $previousAmount,
                    'diff' => $amount - $previousAmount,
                    'rate' => $previousAmount === 0 ? null : round((($amount - $previousAmount) / $previousAmount) * 100, 1),
                ];
            })
            ->sortByDesc('amount')
            ->values();
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array{fixed: int, variable: int, fixed_rate: ?float}
     */
    private function fixedVariable(Collection $transactions): array
    {
        $fixed = 0;
        $variable = 0;

        foreach ($transactions->where('type', 'expense') as $transaction) {
            if ($transaction->category?->isFixedCost()) {
                $fixed += (int) $transaction->amount;
            } else {
                $variable += (int) $transaction->amount;
            }
        }

        $total = $fixed + $variable;

        return [
            'fixed' => $fixed,
            'variable' => $variable,
            'fixed_rate' => $total > 0 ? round(($fixed / $total) * 100, 1) : null,
        ];
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function engelCoefficient(Collection $transactions, int $totalExpense): ?float
    {
        $foodCategoryIds = Category::countsAsFood()->pluck('id');

        if ($foodCategoryIds->isEmpty() || $totalExpense <= 0) {
            return null;
        }

        $food = (int) $transactions
            ->where('type', 'expense')
            ->filter(fn (Transaction $t) => $t->category_id !== null && $foodCategoryIds->contains($t->category_id))
            ->sum('amount');

        return round(($food / $totalExpense) * 100, 1);
    }

    /**
     * その年の最初と最後のスナップショットから総資産の増減を出す。
     *
     * @return array{first: ?AssetSnapshot, last: ?AssetSnapshot, change: ?int}
     */
    private function assets(int $year): array
    {
        $snapshots = AssetSnapshot::query()
            ->whereBetween('snapshot_date', [
                Carbon::create($year, 1, 1)->toDateString(),
                Carbon::create($year, 12, 31)->toDateString(),
            ])
            ->orderBy('snapshot_date')
            ->get();

        $first = $snapshots->first();
        $last = $snapshots->last();

        return [
            'first' => $first,
            'last' => $last,
            'change' => ($first && $last && $first->isNot($last))
                ? $last->total_amount - $first->total_amount
                : null,
        ];
    }
}
