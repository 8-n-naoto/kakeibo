<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvestmentAccount extends Model
{
    use HasFactory;

    public const TYPES = [
        'nisa_growth' => 'NISA成長投資枠',
        'nisa_tsumitate' => 'NISAつみたて投資枠',
        'ideco' => 'iDeCo',
    ];

    protected $fillable = [
        'account_type',
        'fiscal_year',
        'annual_limit',
        'contributed_amount',
        'evaluation_amount',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'annual_limit' => 'integer',
            'contributed_amount' => 'integer',
            'evaluation_amount' => 'integer',
        ];
    }

    public function getAccountTypeLabelAttribute(): string
    {
        return self::TYPES[$this->account_type] ?? $this->account_type;
    }

    /**
     * 年間投資枠の消化率(%)
     */
    public function getUsageRateAttribute(): float
    {
        if ($this->annual_limit <= 0) {
            return 0.0;
        }

        return round(($this->contributed_amount / $this->annual_limit) * 100, 1);
    }

    /**
     * 含み損益(評価額 - 拠出額)
     */
    public function getGainLossAttribute(): int
    {
        return $this->evaluation_amount - $this->contributed_amount;
    }
}
