<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class InvestmentAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_type' => 'nisa_growth',
            'fiscal_year' => now()->year,
            'annual_limit' => 2400000,
            'contributed_amount' => 600000,
            'evaluation_amount' => 650000,
            'memo' => null,
        ];
    }
}
