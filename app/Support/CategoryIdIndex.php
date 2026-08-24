<?php

namespace App\Support;

use App\Models\Category;

/**
 * カテゴリIDの一覧を、1リクエストの間だけ覚えておく。
 *
 * CSV取込の確定は 2,000 行を1回のPOSTで受ける。行ごとのカテゴリを
 * `exists:categories,id` で見ると**行数ぶんの SELECT** が飛ぶので、
 * ID一覧を1回だけ読んで `Rule::in` で照合する。
 *
 * static 変数ではなくコンテナのシングルトンにしてある。static だと
 * テスト間で持ち越されて、次のテストで作ったカテゴリが「存在しない」ことになる。
 */
class CategoryIdIndex
{
    /** @var array<string, list<int>>|null */
    private ?array $byType = null;

    /**
     * @return list<int>
     */
    public function ids(?string $type = null): array
    {
        $this->byType ??= Category::query()
            ->get(['id', 'type'])
            ->groupBy('type')
            ->map(fn ($group) => $group->pluck('id')->map(fn ($id) => (int) $id)->all())
            ->all();

        if ($type === 'income' || $type === 'expense') {
            return $this->byType[$type] ?? [];
        }

        return $this->byType === [] ? [] : array_merge(...array_values($this->byType));
    }

    /** カテゴリを追加・削除したあとに呼ぶ */
    public function forget(): void
    {
        $this->byType = null;
    }
}
