<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_カテゴリ一覧が表示できる(): void
    {
        Category::factory()->create(['name' => '食費', 'expense_nature' => 'variable']);
        Category::factory()->create(['name' => '給与', 'type' => 'income']);

        $response = $this->get(route('categories.index'));

        $response->assertOk();
        $response->assertSee('食費');
        $response->assertSee('給与');
    }

    public function test_カテゴリを追加できる(): void
    {
        $response = $this->post(route('categories.store'), [
            'name' => 'ペット',
            'type' => 'expense',
            'expense_nature' => 'fixed',
            'color' => '#123456',
            'sort_order' => 20,
        ]);

        $response->assertRedirect(route('categories.index'));
        $this->assertDatabaseHas('categories', ['name' => 'ペット', 'expense_nature' => 'fixed']);
    }

    public function test_収入カテゴリでは固定変動の区分が保存されない(): void
    {
        $this->post(route('categories.store'), [
            'name' => 'ボーナス',
            'type' => 'income',
            'expense_nature' => 'fixed',
            'color' => '#123456',
            'sort_order' => 5,
        ]);

        $this->assertDatabaseHas('categories', ['name' => 'ボーナス', 'expense_nature' => null]);
    }

    public function test_同じ名前のカテゴリは追加できない(): void
    {
        Category::factory()->create(['name' => '重複テスト']);

        $response = $this->from(route('categories.create'))->post(route('categories.store'), [
            'name' => '重複テスト',
            'type' => 'expense',
            'expense_nature' => 'variable',
            'color' => '#123456',
            'sort_order' => 1,
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_カテゴリを更新できる(): void
    {
        $category = Category::factory()->create(['name' => '旧名称', 'expense_nature' => 'variable']);

        $response = $this->put(route('categories.update', $category), [
            'name' => '新名称',
            'type' => 'expense',
            'expense_nature' => 'fixed',
            'color' => '#abcdef',
            'sort_order' => 3,
        ]);

        $response->assertRedirect(route('categories.index'));
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => '新名称', 'expense_nature' => 'fixed']);
    }

    public function test_カテゴリを削除すると取引は未分類になる(): void
    {
        $category = Category::factory()->create();
        $transaction = Transaction::factory()->create(['category_id' => $category->id]);

        $this->delete(route('categories.destroy', $category))
            ->assertRedirect(route('categories.index'));

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertNull($transaction->fresh()->category_id);
    }
}
