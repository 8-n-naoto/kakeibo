<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AssetSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'snapshot_date' => now()->startOfMonth()->toDateString(),
            'cash_amount' => $this->faker->numberBetween(100000, 3000000),
            'nisa_amount' => $this->faker->numberBetween(0, 2000000),
            'ideco_amount' => $this->faker->numberBetween(0, 1000000),
            'other_investment_amount' => $this->faker->numberBetween(0, 500000),
            'memo' => null,
        ];
    }
}
