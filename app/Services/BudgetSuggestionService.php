<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 過去の実績から予算を提案する。
 *
 * 予算を決めるのが面倒で結局つけない、というのが家計簿が続かない理由のひとつなので、
 * 「だいたいこのくらい」を実績から出して、あとは数字を直して確定するだけにする。
 *
 * ■ 何を出すか
 *   - 変動費は**中央値**。平均だと、1回の家電購入や旅行で予算が跳ね上がってしまう。
 *   - 固定費は**直近の実績**。家賃や通信費は上がったら上がったままなので、
 *     中央値だと古い安い金額に引きずられる。
 *   - 支出全体は「月ごとの合計の中央値」。カテゴリ別の提案を足し算すると、
 *     たまたま同じ月に重ならない支出まで合算されて過大になる。
 *
 * ■ 何を出さないか
 *   - 実績のある月が MIN_MONTHS 未満なら提案しない。3ヶ月分も無い数字で
 *     予算を決めさせるのは、当てずっぽうを「提案」と言い換えているだけになる。
 */
class BudgetSuggestionService
{
    /** 何ヶ月分の実績を見るか */
    public const LOOKBACK_MONTHS = 6;

    /** これだけの月数の実績が無ければ提案しない */
    public const MIN_MONTHS = 3;

    /** lookback に指定できる上限 */
    public const MAX_LOOKBACK = 24;

    public function __construct(private readonly BudgetService $budgetService)
    {
    }

    /**
     * @return array{
     *     month: Carbon,
     *     lookback: int,
     *     months: array<int, string>,
     *     available: int,
     *     category_months: array<int, string>,
     *     enough: bool,
     *     overall: ?array,
     *     categories: Collection,
     *     uncategorized: int,
     * }
     */
    public function suggest(Carbon $month, int $lookback = self::LOOKBACK_MONTHS): array
    {
        $month = $month->copy()->startOfMonth();
        // MIN_MONTHS より短い期間を指定されると、どうやっても「提案できません」にしかならない
        $lookback = max(self::MIN_MONTHS, min($lookback, self::MAX_LOOKBACK));

        // 対象月そのものは含めない。まだ終わっていない月の実績で予算を決めない。
        $start = $month->copy()->subMonths($lookback)->startOfMonth();
        $end = $month->copy()->subMonth()->endOfMonth();

        /** @var array<int, string> $keys 古い順の 'Y-m' */
        $keys = [];

        for ($i = $lookback; $i >= 1; $i--) {
            $keys[] = $month->copy()->subMonths($i)->format('Y-m');
        }

        $totals = array_fill_keys($keys, 0);
        $uncategorizedByMonth = array_fill_keys($keys, 0);
        $byCategory = [];
        $uncategorized = 0;

        Transaction::query()
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('id')
            ->select(['id', 'transaction_date', 'category_id', 'amount'])
            ->chunk(1000, function ($chunk) use (&$totals, &$uncategorizedByMonth, &$byCategory, &$uncategorized): void {
                foreach ($chunk as $transaction) {
                    $key = $transaction->transaction_date?->format('Y-m');

                    if ($key === null || ! array_key_exists($key, $totals)) {
                        continue;
                    }

                    $amount = (int) $transaction->amount;
                    $totals[$key] += $amount;

                    if ($transaction->category_id === null) {
                        $uncategorized += $amount;
                        $uncategorizedByMonth[$key] += $amount;

                        continue;
                    }

                    $id = (int) $transaction->category_id;
                    $byCategory[$id][$key] = ($byCategory[$id][$key] ?? 0) + $amount;
                }
            });

        // 「実績のある月」＝その月に支出が1件でも記録されている月。
        // 記録していない月を 0 円として混ぜると、中央値が不当に下がる。
        $active = array_values(array_filter($keys, fn (string $key): bool => $totals[$key] > 0));

        // カテゴリ別はさらに「カテゴリ分けされた支出がある月」だけを見る。
        // CSV取込した直後などは category_id が null の行しか無い月ができ、
        // その月を 0 円としてカテゴリの実績に混ぜると中央値が半分近くまで下がる。
        $activeForCategories = array_values(array_filter(
            $keys,
            fn (string $key): bool => $totals[$key] - $uncategorizedByMonth[$key] > 0,
        ));

        $enough = count($active) >= self::MIN_MONTHS;

        if (! $enough) {
            return [
                'month' => $month,
                'lookback' => $lookback,
                'months' => $active,
                'available' => count($active),
                'category_months' => $activeForCategories,
                'enough' => false,
                'overall' => null,
                'categories' => collect(),
                'uncategorized' => $uncategorized,
            ];
        }

        $applied = $this->budgetService->appliedAmounts($month);

        $overall = $this->buildRow(
            null,
            '支出全体',
            array_map(fn (string $key): int => $totals[$key], $active),
            $active,
            $applied['overall'] ?? null,
            false,
        );

        $categories = count($activeForCategories) >= self::MIN_MONTHS
            ? Category::expense()->orderBy('sort_order')->orderBy('id')->get()
                ->map(function (Category $category) use ($byCategory, $activeForCategories, $applied): array {
                    $series = array_map(
                        fn (string $key): int => $byCategory[$category->id][$key] ?? 0,
                        $activeForCategories,
                    );

                    return $this->buildRow(
                        $category,
                        $category->name,
                        $series,
                        $activeForCategories,
                        $applied[$category->id] ?? null,
                        $category->isFixedCost(),
                    );
                })
                // 提案額が 0 円になるカテゴリは出さない。
                // 0円予算を登録すると「消化率0%＝順調」と表示され、予算が無いより悪くなる。
                ->filter(fn (array $row): bool => $row['suggested'] > 0)
                ->values()
            : collect();

        return [
            'month' => $month,
            'lookback' => $lookback,
            'months' => $active,
            'available' => count($active),
            'category_months' => $activeForCategories,
            'enough' => true,
            'overall' => $overall,
            'categories' => $categories,
            'uncategorized' => $uncategorized,
        ];
    }

    /**
     * @param  array<int, int>  $series  実績のある月の金額（古い順）
     * @param  array<int, string>  $months  対応する 'Y-m'（古い順）
     * @return array{
     *     key: string, category: ?Category, name: string, series: array<string, int>,
     *     median: int, average: int, min: int, max: int, latest: int,
     *     suggested: int, current: ?int, diff: ?int, basis: string, fixed: bool,
     * }
     */
    private function buildRow(
        ?Category $category,
        string $name,
        array $series,
        array $months,
        ?int $current,
        bool $fixed,
    ): array {
        $median = self::median($series);
        $latest = $series === [] ? 0 : (int) $series[count($series) - 1];

        if ($fixed) {
            // 固定費は直近の実績。ただし直近が 0（その月だけ引き落としが無い等）なら中央値に逃がす
            $base = $latest > 0 ? $latest : $median;
            $basis = $latest > 0 ? '固定費：直近の実績' : '固定費：直近が0のため中央値';
        } else {
            $base = $median;
            // 「直近N ヶ月」とは書かない。記録の無い月は飛ばしているので連続とは限らない
            $basis = sprintf('変動費：実績%dヶ月の中央値', count($series));
        }

        $suggested = self::roundUp($base);

        return [
            'key' => $category === null ? 'overall' : (string) $category->id,
            'category' => $category,
            'name' => $name,
            'series' => array_combine($months, $series) ?: [],
            'median' => $median,
            'average' => $series === [] ? 0 : (int) round(array_sum($series) / count($series)),
            'min' => $series === [] ? 0 : min($series),
            'max' => $series === [] ? 0 : max($series),
            'latest' => $latest,
            'suggested' => $suggested,
            'current' => $current,
            'diff' => $current === null ? null : $suggested - $current,
            'basis' => $basis,
            'fixed' => $fixed,
        ];
    }

    /**
     * 中央値。偶数個なら真ん中2つの平均。
     *
     * @param  array<int, int>  $values
     */
    public static function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (int) $values[$middle];
        }

        return (int) round(((int) $values[$middle - 1] + (int) $values[$middle]) / 2);
    }

    /**
     * 予算として書きやすい数字に切り上げる。
     * 32,480円 → 33,000円 のように、桁に応じた単位で丸める。
     */
    public static function roundUp(int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        $unit = match (true) {
            $amount >= 10000 => 1000,
            $amount >= 1000 => 100,
            default => 10,
        };

        return (int) (ceil($amount / $unit) * $unit);
    }
}
