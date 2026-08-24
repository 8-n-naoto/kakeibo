<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'type' => 'expense',
            'expense_nature' => 'variable',
            'counts_as_food' => false,
            'color' => '#94a3b8',
            'sort_order' => 1,
        ];
    }
}
