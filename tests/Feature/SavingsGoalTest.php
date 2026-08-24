<?php

namespace Tests\Feature;

use App\Models\SavingsGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavingsGoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_貯蓄目標の一覧が表示できる(): void
    {
        SavingsGoal::factory()->create();

        $response = $this->get(route('savings-goals.index'));

        $response->assertOk();
    }

    public function test_貯蓄目標を登録できる(): void
    {
        $response = $this->post(route('savings-goals.store'), [
            'name' => '旅行資金',
            'target_amount' => 500000,
            'current_amount' => 100000,
            'target_date' => now()->addMonths(6)->format('Y-m-d'),
        ]);

        $response->assertRedirect(route('savings-goals.index'));
        $this->assertDatabaseHas('savings_goals', ['name' => '旅行資金']);
    }

    public function test_進捗率が正しく計算される(): void
    {
        $goal = SavingsGoal::factory()->create([
            'target_amount' => 400000,
            'current_amount' => 100000,
        ]);

        $this->assertSame(25.0, $goal->progress_rate);
    }

    public function test_目標金額が0の場合は進捗率0になる(): void
    {
        $goal = SavingsGoal::factory()->make([
            'target_amount' => 0,
            'current_amount' => 0,
        ]);

        $this->assertSame(0.0, $goal->progress_rate);
    }

    public function test_月あたりの必要貯蓄額が計算される(): void
    {
        $goal = SavingsGoal::factory()->create([
            'target_amount' => 240000,
            'current_amount' => 0,
            'target_date' => now()->addMonths(12)->startOfMonth(),
        ]);

        $this->assertSame(20000, $goal->monthly_required_amount);
    }
}
