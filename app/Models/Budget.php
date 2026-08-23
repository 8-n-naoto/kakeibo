<?php

namespace App\Models;

use App\Models\Concerns\StoresDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 月次予算。
 *
 * - category_id が null … 支出全体の予算
 * - month が null … 毎月適用されるデフォルト予算(月別の設定が無ければこれが使われる)
 */
class Budget extends Model
{
    use HasFactory;
    use StoresDateOnly;

    protected $fillable = [
        'category_id',
        'month',
        'amount',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    /** 日付のみを保存する(MySQL/SQLiteで同じ値になるようにする) */
    protected function month(): Attribute
    {
        return static::dateOnlyAttribute();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** その月に適用され得る予算(その月専用 + デフォルト)だけに絞る */
    public function scopeApplicableTo(Builder $query, Carbon $month): Builder
    {
        return $query->where(function (Builder $q) use ($month) {
            $q->whereNull('month')->orWhere('month', $month->copy()->startOfMonth()->toDateString());
        });
    }

    public function isDefault(): bool
    {
        return $this->month === null;
    }

    public function getScopeLabelAttribute(): string
    {
        return $this->category?->name ?? '支出全体';
    }

    public function getPeriodLabelAttribute(): string
    {
        return $this->month?->format('Y年n月') ?? '毎月(デフォルト)';
    }
}
