<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecurringTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'type' => 'expense',
            'category_id' => Category::factory(),
            'shop_name' => null,
            'memo' => null,
            'amount' => $this->faker->numberBetween(1000, 100000),
            'day_of_month' => 1,
            'starts_on' => null,
            'ends_on' => null,
            'is_active' => true,
        ];
    }
}
