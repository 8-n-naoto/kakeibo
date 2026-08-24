<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceiptImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'path',
        'status',
        'raw_response',
        'parsed_data',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'raw_response' => 'array',
            'parsed_data' => 'array',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * 解析が終わっていて、まだ家計簿に登録していないレシート。
     */
    public function scopeReadyForRegistration(Builder $query): Builder
    {
        return $query->where('status', 'processed')->whereDoesntHave('transactions');
    }

    /**
     * アップロード済みで、まだAIに読ませていないレシート。
     */
    public function scopeAwaitingParse(Builder $query): Builder
    {
        return $query->where('status', 'pending')->whereDoesntHave('transactions');
    }

    /**
     * すでに家計簿へ登録済みか。二重計上のガードに使う。
     */
    public function isRegistered(): bool
    {
        return $this->transactions()->exists();
    }
}
