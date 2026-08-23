<?php

namespace Tests\Unit;

use App\Models\SavingsGoal;
use PHPUnit\Framework\TestCase;

class SavingsGoalProgressTest extends TestCase
{
    public function test_残り金額が正しく計算される(): void
    {
        $goal = new SavingsGoal([
            'target_amount' => 500000,
            'current_amount' => 350000,
        ]);

        $this->assertSame(150000, $goal->remaining_amount);
    }

    public function test_目標を超過している場合は残り金額が0になる(): void
    {
        $goal = new SavingsGoal([
            'target_amount' => 500000,
            'current_amount' => 600000,
        ]);

        $this->assertSame(0, $goal->remaining_amount);
        $this->assertSame(120.0, $goal->progress_rate);
    }
}
