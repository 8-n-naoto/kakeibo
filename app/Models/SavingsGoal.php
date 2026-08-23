<?php

namespace App\Models;

use App\Models\Concerns\StoresDateOnly;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class SavingsGoal extends Model
{
    use HasFactory;
    use StoresDateOnly;

    protected $fillable = [
        'name',
        'target_amount',
        'current_amount',
        'target_date',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'integer',
            'current_amount' => 'integer',
        ];
    }

    /** 日付のみを保存する(MySQL/SQLiteで同じ値になるようにする) */
    protected function targetDate(): Attribute
    {
        return static::dateOnlyAttribute();
    }

    /**
     * 進捗率(%)。target_amountが0の場合は0を返す。100%を上限にはしない(超過も見える化する)。
     */
    public function getProgressRateAttribute(): float
    {
        if ($this->target_amount <= 0) {
            return 0.0;
        }

        return round(($this->current_amount / $this->target_amount) * 100, 1);
    }

    public function getRemainingAmountAttribute(): int
    {
        return max(0, $this->target_amount - $this->current_amount);
    }

    /**
     * 達成期限までに毎月必要な貯蓄額。期限が過去/未設定の場合はnull。
     */
    public function getMonthlyRequiredAmountAttribute(): ?int
    {
        if (! $this->target_date) {
            return null;
        }

        $now = Carbon::now()->startOfMonth();
        $target = $this->target_date->copy()->startOfMonth();

        $monthsRemaining = $now->diffInMonths($target, false);

        if ($monthsRemaining <= 0) {
            return $this->remaining_amount > 0 ? $this->remaining_amount : 0;
        }

        return (int) ceil($this->remaining_amount / $monthsRemaining);
    }
}
