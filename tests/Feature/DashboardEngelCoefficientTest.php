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
        $food = Category::factory()->create(['name' => '食費', 'type' => 'expense', 'counts_as_food' => true]);
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
        Category::factory()->create(['name' => '食費', 'type' => 'expense', 'counts_as_food' => true]);

        $response = $this->get(route('dashboard', ['month' => now()->format('Y-m')]));

        $response->assertOk();
        $response->assertViewHas('engelCoefficient', null);
    }

    public function test_対象カテゴリを複数選ぶと合算して計算される(): void
    {
        $food = Category::factory()->create(['name' => '食費', 'type' => 'expense', 'counts_as_food' => true]);
        $eatingOut = Category::factory()->create(['name' => '外食', 'type' => 'expense', 'counts_as_food' => true]);
        $other = Category::factory()->create(['name' => '娯楽・趣味', 'type' => 'expense']);

        foreach ([[$food, 20000], [$eatingOut, 10000], [$other, 70000]] as [$category, $amount]) {
            Transaction::factory()->create([
                'category_id' => $category->id,
                'type' => 'expense',
                'amount' => $amount,
                'transaction_date' => now(),
            ]);
        }

        $response = $this->get(route('dashboard', ['month' => now()->format('Y-m')]));

        $response->assertOk();
        $response->assertViewHas('engelCoefficient', 30.0);
        $response->assertViewHas('foodExpense', 30000);
    }

    public function test_カテゴリ名を変えても対象フラグが残っていれば計算できる(): void
    {
        $food = Category::factory()->create(['name' => '食料品', 'type' => 'expense', 'counts_as_food' => true]);

        Transaction::factory()->create([
            'category_id' => $food->id,
            'type' => 'expense',
            'amount' => 25000,
            'transaction_date' => now(),
        ]);

        Transaction::factory()->create([
            'category_id' => null,
            'type' => 'expense',
            'amount' => 75000,
            'transaction_date' => now(),
        ]);

        $response = $this->get(route('dashboard', ['month' => now()->format('Y-m')]));

        $response->assertOk();
        $response->assertViewHas('engelCoefficient', 25.0);
    }

    public function test_対象カテゴリが未設定ならnullで未設定と表示される(): void
    {
        $category = Category::factory()->create(['name' => '食費', 'type' => 'expense', 'counts_as_food' => false]);

        Transaction::factory()->create([
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 30000,
            'transaction_date' => now(),
        ]);

        $response = $this->get(route('dashboard', ['month' => now()->format('Y-m')]));

        $response->assertOk();
        $response->assertViewHas('engelCoefficient', null);
        $response->assertViewHas('foodCategoriesConfigured', false);
        $response->assertSee('未設定');
    }
}
