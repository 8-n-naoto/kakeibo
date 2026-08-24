<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            // 支出
            ['name' => '食費', 'type' => 'expense', 'expense_nature' => 'variable', 'counts_as_food' => true, 'color' => '#f97316', 'sort_order' => 1],
            ['name' => '外食', 'type' => 'expense', 'expense_nature' => 'variable', 'color' => '#fb923c', 'sort_order' => 2],
            ['name' => '日用品', 'type' => 'expense', 'expense_nature' => 'variable', 'color' => '#22c55e', 'sort_order' => 3],
            ['name' => '住居費', 'type' => 'expense', 'expense_nature' => 'fixed', 'color' => '#0ea5e9', 'sort_order' => 4],
            ['name' => '水道光熱費', 'type' => 'expense', 'expense_nature' => 'fixed', 'color' => '#38bdf8', 'sort_order' => 5],
            ['name' => '通信費', 'type' => 'expense', 'expense_nature' => 'fixed', 'color' => '#6366f1', 'sort_order' => 6],
            ['name' => '交通費', 'type' => 'expense', 'expense_nature' => 'variable', 'color' => '#8b5cf6', 'sort_order' => 7],
            ['name' => '医療・健康', 'type' => 'expense', 'expense_nature' => 'variable', 'color' => '#ec4899', 'sort_order' => 8],
            ['name' => '衣服・美容', 'type' => 'expense', 'expense_nature' => 'variable', 'color' => '#f43f5e', 'sort_order' => 9],
            ['name' => '娯楽・趣味', 'type' => 'expense', 'expense_nature' => 'variable', 'color' => '#eab308', 'sort_order' => 10],
            ['name' => '教育', 'type' => 'expense', 'expense_nature' => 'fixed', 'color' => '#14b8a6', 'sort_order' => 11],
            ['name' => '保険', 'type' => 'expense', 'expense_nature' => 'fixed', 'color' => '#0891b2', 'sort_order' => 12],
            ['name' => 'サブスク', 'type' => 'expense', 'expense_nature' => 'fixed', 'color' => '#a855f7', 'sort_order' => 13],
            ['name' => 'その他支出', 'type' => 'expense', 'expense_nature' => 'variable', 'color' => '#94a3b8', 'sort_order' => 99],
            // 収入
            ['name' => '給与', 'type' => 'income', 'color' => '#16a34a', 'sort_order' => 1],
            ['name' => '副業', 'type' => 'income', 'color' => '#65a30d', 'sort_order' => 2],
            ['name' => 'その他収入', 'type' => 'income', 'color' => '#84cc16', 'sort_order' => 99],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['name' => $category['name'], 'type' => $category['type']],
                $category
            );
        }

        // カテゴリIDの一覧などをリクエスト内で覚えている。作った直後に
        // 「存在しないカテゴリ」と言われないよう捨てる（どれも scoped）
        app()->forgetScopedInstances();
    }
}
