<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transaction_date' => now()->toDateString(),
            'type' => 'expense',
            'category_id' => Category::factory(),
            'shop_name' => $this->faker->company(),
            'memo' => null,
            'amount' => $this->faker->numberBetween(500, 20000),
        ];
    }
}
