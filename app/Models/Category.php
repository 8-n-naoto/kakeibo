<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    /** 固定費: 毎月ほぼ一定額かかる支出(家賃・通信費など) */
    public const NATURE_FIXED = 'fixed';

    /** 変動費: 月によって金額が変わる支出(食費・娯楽など) */
    public const NATURE_VARIABLE = 'variable';

    public const NATURES = [
        self::NATURE_FIXED => '固定費',
        self::NATURE_VARIABLE => '変動費',
    ];

    protected $fillable = [
        'name',
        'type',
        'expense_nature',
        'counts_as_food',
        'color',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'counts_as_food' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /**
     * このカテゴリを使っている定期支出。
     *
     * 削除時は `nullOnDelete`（未分類になる）。
     */
    public function recurringTransactions(): HasMany
    {
        return $this->hasMany(RecurringTransaction::class);
    }

    /**
     * このカテゴリを指す学習ルール。
     *
     * 削除時は `cascadeOnDelete`（**一緒に消える**）。
     */
    public function merchantCategoryRules(): HasMany
    {
        return $this->hasMany(MerchantCategoryRule::class);
    }

    /**
     * 削除したときに巻き添えになるものの件数を一緒に読む。
     *
     * ここを通さずに categories/index を描くと、確認ダイアログが
     * 「巻き添え0件」と嘘をつき、しかも削除が必ず拒否される。
     */
    public function scopeWithImpactCounts(Builder $query): Builder
    {
        return $query->withCount([
            'transactions',
            'budgets',
            'recurringTransactions',
            'merchantCategoryRules',
            // 学習ルールは取込のたびに覚え直せるが、手で登録したものは戻らない
            'merchantCategoryRules as manual_rules_count' => fn ($inner) => $inner
                ->where('source', MerchantCategoryRule::SOURCE_MANUAL),
        ]);
    }

    public function scopeExpense(Builder $query): Builder
    {
        return $query->where('type', 'expense');
    }

    public function scopeIncome(Builder $query): Builder
    {
        return $query->where('type', 'income');
    }

    /** エンゲル係数の分子(食料費)に数えるカテゴリ */
    public function scopeCountsAsFood(Builder $query): Builder
    {
        return $query->where('type', 'expense')->where('counts_as_food', true);
    }

    public function isFixedCost(): bool
    {
        return $this->type === 'expense' && $this->expense_nature === self::NATURE_FIXED;
    }

    public function getExpenseNatureLabelAttribute(): ?string
    {
        if ($this->type !== 'expense') {
            return null;
        }

        return self::NATURES[$this->expense_nature] ?? self::NATURES[self::NATURE_VARIABLE];
    }
}
