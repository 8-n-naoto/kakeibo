<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 「いつもより明らかに多い支出」を見つけて知らせるサービス。
 *
 * 予算アラートは予算を設定したカテゴリにしか出ない。
 * 予算を決めていないカテゴリの急増や、単発の高額支出はここで拾う。
 *
 * 過去データが少ないうちに騒がないよう、次の条件をすべて満たしたものだけを出す。
 *   - カテゴリの急増: 過去 LOOKBACK_MONTHS ヶ月のうち MIN_HISTORY_MONTHS ヶ月以上の実績がある
 *                     かつ 平均の CATEGORY_RATIO 倍以上 かつ 差額が MIN_DIFFERENCE 円以上
 *   - 単発の高額:     同カテゴリに MIN_HISTORY_TRANSACTIONS 件以上の実績がある
 *                     かつ 平均単価の TRANSACTION_RATIO 倍以上 かつ MIN_TRANSACTION_AMOUNT 円以上
 */
class SpendingAnomalyService
{
    /** さかのぼって平均を取る月数(当月は含まない) */
    public const LOOKBACK_MONTHS = 6;

    /** カテゴリの急増を判定するのに必要な実績月数 */
    public const MIN_HISTORY_MONTHS = 3;

    /** 平均の何倍で「急増」とみなすか */
    public const CATEGORY_RATIO = 1.5;

    /** 金額差がこれ未満なら騒がない(円) */
    public const MIN_DIFFERENCE = 3000;

    /** 単発の高額を判定するのに必要な同カテゴリの取引件数 */
    public const MIN_HISTORY_TRANSACTIONS = 5;

    /** 平均単価の何倍で「高額」とみなすか */
    public const TRANSACTION_RATIO = 3.0;

    /** 単発の高額とみなす下限(円) */
    public const MIN_TRANSACTION_AMOUNT = 10000;

    public const TYPE_CATEGORY_SPIKE = 'category_spike';

    public const TYPE_LARGE_TRANSACTION = 'large_transaction';

    /**
     * 指定月の気になる支出。影響の大きい順に返す。
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function detect(Carbon $month): Collection
    {
        $month = $month->copy()->startOfMonth();

        $current = $this->expensesBetween($month, $month->copy()->endOfMonth());

        if ($current->isEmpty()) {
            return collect();
        }

        $historyStart = $month->copy()->subMonths(self::LOOKBACK_MONTHS)->startOfMonth();
        $history = $this->expensesBetween($historyStart, $month->copy()->subMonth()->endOfMonth());

        return $this->categorySpikes($current, $history, $month)
            ->concat($this->largeTransactions($current, $history))
            ->sortByDesc('impact')
            ->values();
    }

    /**
     * カテゴリ単位の急増。
     *
     * @param  Collection<int, Transaction>  $current
     * @param  Collection<int, Transaction>  $history
     * @return Collection<int, array<string, mixed>>
     */
    private function categorySpikes(Collection $current, Collection $history, Carbon $month): Collection
    {
        $historyByCategory = $history->groupBy(fn (Transaction $t) => $this->categoryKey($t));

        return $current
            ->groupBy(fn (Transaction $t) => $this->categoryKey($t))
            ->map(function (Collection $rows, string $key) use ($historyByCategory): ?array {
                $past = $historyByCategory->get($key, collect());

                // その月ごとの合計を出す
                $monthlyTotals = $past
                    ->groupBy(fn (Transaction $t) => $t->transaction_date->format('Y-m'))
                    ->map(fn (Collection $items) => (int) $items->sum('amount'));

                if ($monthlyTotals->count() < self::MIN_HISTORY_MONTHS) {
                    return null;
                }

                $average = (int) round($monthlyTotals->avg());
                $amount = (int) $rows->sum('amount');
                $difference = $amount - $average;

                if ($average <= 0 || $difference < self::MIN_DIFFERENCE) {
                    return null;
                }

                if ($amount < $average * self::CATEGORY_RATIO) {
                    return null;
                }

                return [
                    'type' => self::TYPE_CATEGORY_SPIKE,
                    'name' => $this->categoryName($rows->first()),
                    'amount' => $amount,
                    'average' => $average,
                    'difference' => $difference,
                    'ratio' => round($amount / $average, 1),
                    'history_months' => $monthlyTotals->count(),
                    'category_id' => $rows->first()->category_id,
                    'impact' => $difference,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * 単発の高額支出。
     *
     * @param  Collection<int, Transaction>  $current
     * @param  Collection<int, Transaction>  $history
     * @return Collection<int, array<string, mixed>>
     */
    private function largeTransactions(Collection $current, Collection $history): Collection
    {
        $historyByCategory = $history->groupBy(fn (Transaction $t) => $this->categoryKey($t));

        return $current
            ->map(function (Transaction $transaction) use ($historyByCategory): ?array {
                if ($transaction->amount < self::MIN_TRANSACTION_AMOUNT) {
                    return null;
                }

                $past = $historyByCategory->get($this->categoryKey($transaction), collect());

                if ($past->count() < self::MIN_HISTORY_TRANSACTIONS) {
                    return null;
                }

                $average = (int) round($past->avg('amount'));

                if ($average <= 0 || $transaction->amount < $average * self::TRANSACTION_RATIO) {
                    return null;
                }

                return [
                    'type' => self::TYPE_LARGE_TRANSACTION,
                    'name' => $this->categoryName($transaction),
                    'transaction' => $transaction,
                    'amount' => (int) $transaction->amount,
                    'average' => $average,
                    'ratio' => round($transaction->amount / $average, 1),
                    'impact' => (int) $transaction->amount - $average,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function expensesBetween(Carbon $from, Carbon $to): Collection
    {
        return Transaction::with('category')
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->get();
    }

    private function categoryKey(Transaction $transaction): string
    {
        return (string) ($transaction->category_id ?? 'none');
    }

    private function categoryName(Transaction $transaction): string
    {
        return $transaction->category?->name ?? '未分類';
    }
}
