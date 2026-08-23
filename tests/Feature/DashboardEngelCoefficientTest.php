<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardEngelCoefficientTest extends TestCase
{
    use RefreshDatabase;

    public function test_エンゲル係数が食費と支出合計から計算される(): void
    {
        $food = Category::factory()->create(['name' => '食費', 'type' => 'expense']);
        $other = Category::factory()->create(['name' => '娯楽・趣味', 'type' => 'expense']);

        Transaction::factory()->create([
            'category_id' => $food->id,
            'type' => 'expense',
            'amount' => 30000,
            'transaction_date' => now(),
        ]);

        Transaction::factory()->create([
            'category_id' => $other->id,
            'type' => 'expense',
            'amount' => 70000,
            'transaction_date' => now(),
        ]);

        $response = $this->get(route('dashboard', ['month' => now()->format('Y-m')]));

        $response->assertOk();
        $response->assertViewHas('engelCoefficient', 30.0);
    }

    public function test_支出が0の月はエンゲル係数がnullになる(): void
    {
        $response = $this->get(route('dashboard', ['month' => now()->format('Y-m')]));

        $response->assertOk();
        $response->assertViewHas('engelCoefficient', null);
    }
}
