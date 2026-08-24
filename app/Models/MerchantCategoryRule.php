<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 店名 → カテゴリ の対応ルール。
 */
class MerchantCategoryRule extends Model
{
    use HasFactory;

    public const SOURCE_LEARNED = 'learned';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_LABELS = [
        self::SOURCE_LEARNED => '自動学習',
        self::SOURCE_MANUAL => '手動登録',
    ];

    protected $fillable = [
        'pattern',
        'display_name',
        'category_id',
        'source',
        'hit_count',
    ];

    protected function casts(): array
    {
        return [
            'hit_count' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source;
    }
}
