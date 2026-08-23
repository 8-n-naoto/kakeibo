<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BudgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => null,
            'month' => null,
            'amount' => 100000,
            'memo' => null,
        ];
    }
}
