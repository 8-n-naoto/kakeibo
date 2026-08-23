<?php

namespace App\Models;

use App\Models\Concerns\StoresDateOnly;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;
    use StoresDateOnly;

    protected $fillable = [
        'transaction_date',
        'type',
        'category_id',
        'shop_name',
        'memo',
        'amount',
        'receipt_image_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    /** 日付のみを保存する(MySQL/SQLiteで同じ値になるようにする) */
    protected function transactionDate(): Attribute
    {
        return static::dateOnlyAttribute();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function receiptImage(): BelongsTo
    {
        return $this->belongsTo(ReceiptImage::class);
    }
}
