<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SavingsGoalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'target_amount' => 1000000,
            'current_amount' => 300000,
            'target_date' => now()->addMonths(10)->toDateString(),
            'memo' => null,
        ];
    }
}
