<?php

namespace App\Services;

use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 定期支出（家賃・サブスクなど）を月次で取引として計上するサービス。
 *
 * 同じ月に二度計上しないよう、取引に `recurring_transaction_id` を持たせて突き合わせる。
 * すでに計上済みの取引は「金額を手で直したかもしれない」ので上書きしない。
 */
class RecurringTransactionService
{
    /**
     * その月に適用される定期支出の一覧。
     *
     * @return Collection<int, RecurringTransaction>
     */
    public function applicableTo(Carbon $month): Collection
    {
        return RecurringTransaction::with('category')
            ->active()
            ->orderBy('day_of_month')
            ->orderBy('id')
            ->get()
            ->filter(fn (RecurringTransaction $rule) => $rule->appliesTo($month))
            ->values();
    }

    /**
     * その月にまだ計上されていない定期支出。
     *
     * @return Collection<int, RecurringTransaction>
     */
    public function pendingFor(Carbon $month): Collection
    {
        $posted = $this->postedRuleIds($month);

        return $this->applicableTo($month)
            ->reject(fn (RecurringTransaction $rule) => $posted->contains($rule->id))
            ->values();
    }

    /**
     * その月の未計上分を取引として登録する。
     *
     * @return array{created: int, skipped: int, mismatched: int, month: Carbon}
     */
    public function post(Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();
        $applicable = $this->applicableTo($month);

        $created = 0;
        $skipped = 0;
        $mismatched = 0;

        DB::transaction(function () use ($applicable, $month, &$created, &$skipped, &$mismatched) {
            // 計上済みの判定はトランザクションの中で取り直す
            // （ボタンのダブルタップで2本同時に走ったときに、両方が「未計上」と見ないように）
            $posted = $this->postedRuleIds($month);

            foreach ($applicable as $rule) {
                if ($posted->contains($rule->id)) {
                    $skipped++;

                    continue;
                }

                // カテゴリの種別が定期支出の種別と食い違っているルールは、
                // 毎月「集計が合わない取引」を作り続ける。カテゴリを外して計上し、
                // 未分類として人の目に留まるようにする（計上そのものは止めない）。
                $categoryId = $rule->category_id;

                $categoryMismatch = $categoryId !== null && $rule->category?->type !== $rule->type;

                if ($categoryMismatch) {
                    $categoryId = null;
                }

                try {
                    Transaction::create([
                        'transaction_date' => $rule->scheduledDateFor($month)->toDateString(),
                        'type' => $rule->type,
                        'category_id' => $categoryId,
                        'shop_name' => $rule->shop_name,
                        'memo' => $rule->memo ?: $rule->name,
                        'amount' => $rule->amount,
                        'recurring_transaction_id' => $rule->id,
                    ]);

                    $created++;

                    // 実際に計上した分だけ数える（一意制約で弾かれた分は skipped 側）
                    if ($categoryMismatch) {
                        $mismatched++;
                    }
                } catch (UniqueConstraintViolationException) {
                    // 一意制約（recurring_transaction_id + transaction_date）に弾かれた＝
                    // ほぼ同時の別リクエストが先に計上した。二重計上にはならないのでスキップ扱い。
                    $skipped++;
                }
            }
        });

        return ['created' => $created, 'skipped' => $skipped, 'mismatched' => $mismatched, 'month' => $month];
    }

    /**
     * その月にすでに計上済みの定期支出ID。
     *
     * @return Collection<int, int>
     */
    private function postedRuleIds(Carbon $month): Collection
    {
        return Transaction::query()
            ->whereNotNull('recurring_transaction_id')
            ->whereBetween('transaction_date', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->distinct()
            ->pluck('recurring_transaction_id')
            ->map(fn ($id) => (int) $id);
    }
}
