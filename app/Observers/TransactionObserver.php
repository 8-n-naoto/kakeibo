<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\MerchantCategoryGuesser;
use Illuminate\Support\Collection;

/**
 * 取引が保存されるたびに「店名 → カテゴリ」を覚える。
 *
 * 手動入力・レシート確認・CSV取込のどの経路でも、
 * 保存される時点で人が内容を確認しているので学習の材料として使える。
 *
 * ただし**アプリ自身の推測をそのまま学習し直すことはしない**。
 * CSV取込は200行まとめて登録できるので、辞書の誤爆（例：`beauty salon` を通信費と推測）が
 * 誰にも見られないまま「学習済みルール」に昇格し、以後あらゆる推測より優先されてしまう。
 * 辞書と同じ答えなら覚える意味も無いので、違うときだけ覚える。
 */
class TransactionObserver
{
    /** 1リクエスト内で使い回すカテゴリ一覧 */
    private ?Collection $categories = null;

    public function __construct(private readonly MerchantCategoryGuesser $guesser)
    {
    }

    public function saved(Transaction $transaction): void
    {
        if ($transaction->category_id === null) {
            return;
        }

        // 収入は学習しない。
        // 学習ルールは支出のカテゴリ推測にしか使わないので、
        // 「スーパーで返金を受けた」を覚えると、次のCSV取込で支出行に
        // 収入カテゴリが割り当てられ、月次集計が壊れる。
        if ($transaction->type !== 'expense') {
            return;
        }

        // 金額だけ直したような更新では覚え直さない
        if (! $transaction->wasRecentlyCreated && ! $transaction->wasChanged(['shop_name', 'category_id'])) {
            return;
        }

        // 辞書がもともと同じ答えを出すなら、ルールとして固定する必要がない
        if (MerchantCategoryGuesser::guessByKeyword($transaction->shop_name, $this->categories()) === $transaction->category_id) {
            return;
        }

        $this->guesser->remember($transaction->shop_name, $transaction->category_id);
    }

    /**
     * @return Collection<int, Category>
     */
    private function categories(): Collection
    {
        return $this->categories ??= Category::expense()->get(['id', 'name', 'type']);
    }
}
