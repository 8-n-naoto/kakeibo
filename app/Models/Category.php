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
        'color',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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

    public function scopeExpense(Builder $query): Builder
    {
        return $query->where('type', 'expense');
    }

    public function scopeIncome(Builder $query): Builder
    {
        return $query->where('type', 'income');
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
