<?php

namespace App\Models;

use App\Models\Concerns\StoresDateOnly;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetSnapshot extends Model
{
    use HasFactory;
    use StoresDateOnly;

    protected $fillable = [
        'snapshot_date',
        'cash_amount',
        'nisa_amount',
        'ideco_amount',
        'other_investment_amount',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'cash_amount' => 'integer',
            'nisa_amount' => 'integer',
            'ideco_amount' => 'integer',
            'other_investment_amount' => 'integer',
        ];
    }

    /** 日付のみを保存する(MySQL/SQLiteで同じ値になるようにする) */
    protected function snapshotDate(): Attribute
    {
        return static::dateOnlyAttribute();
    }

    /**
     * その月時点の総資産(現金+NISA+iDeCo+その他投資)
     */
    public function getTotalAmountAttribute(): int
    {
        return $this->cash_amount + $this->nisa_amount + $this->ideco_amount + $this->other_investment_amount;
    }
}
