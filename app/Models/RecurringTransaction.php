<?php

namespace App\Models;

use App\Models\Concerns\StoresDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 毎月決まった収支（家賃・保険・サブスクなど）。
 */
class RecurringTransaction extends Model
{
    use HasFactory;
    use StoresDateOnly;

    protected $fillable = [
        'name',
        'type',
        'category_id',
        'shop_name',
        'memo',
        'amount',
        'day_of_month',
        'starts_on',
        'ends_on',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'day_of_month' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** 日付のみを保存する(MySQL/SQLiteで同じ値になるようにする) */
    protected function startsOn(): Attribute
    {
        return static::dateOnlyAttribute();
    }

    /** 日付のみを保存する(MySQL/SQLiteで同じ値になるようにする) */
    protected function endsOn(): Attribute
    {
        return static::dateOnlyAttribute();
    }

    protected static function booted(): void
    {
        // 後付けの外部キー制約は SQLite では張られないため、
        // 計上済み取引の紐付け解除はアプリ側で明示的に行う。
        // （MySQL の nullOnDelete と二重になるが、先にこちらが走るだけで害はない）
        static::deleting(function (self $recurring) {
            $recurring->transactions()->update(['recurring_transaction_id' => null]);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * その月に計上する対象か（有効期間の内側にあるか）。
     */
    public function appliesTo(Carbon $month): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $target = $month->copy()->startOfMonth();

        if ($this->starts_on && $this->starts_on->copy()->startOfMonth()->gt($target)) {
            return false;
        }

        if ($this->ends_on && $this->ends_on->copy()->startOfMonth()->lt($target)) {
            return false;
        }

        return true;
    }

    /**
     * その月に実際に計上される日付。月末を超える指定は末日に丸める。
     */
    public function scheduledDateFor(Carbon $month): Carbon
    {
        $start = $month->copy()->startOfMonth();
        $day = min(max($this->day_of_month ?: 1, 1), $start->daysInMonth);

        return $start->copy()->day($day);
    }

    public function typeLabel(): string
    {
        return $this->type === 'income' ? '収入' : '支出';
    }
}
