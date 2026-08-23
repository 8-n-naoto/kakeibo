<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_取引一覧が表示できる(): void
    {
        Transaction::factory()->create(['shop_name' => 'テストストア']);

        $response = $this->get(route('transactions.index'));

        $response->assertOk();
        $response->assertSee('テストストア');
    }

    public function test_取引を登録できる(): void
    {
        $category = Category::factory()->create();

        $response = $this->post(route('transactions.store'), [
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'category_id' => $category->id,
            'shop_name' => 'スーパーA',
            'amount' => 3200,
        ]);

        $response->assertRedirect(route('transactions.index'));
        $this->assertDatabaseHas('transactions', [
            'shop_name' => 'スーパーA',
            'amount' => 3200,
            'type' => 'expense',
        ]);
    }

    public function test_金額が未入力だと登録できない(): void
    {
        $response = $this->from(route('transactions.create'))->post(route('transactions.store'), [
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, Transaction::count());
    }

    public function test_取引を更新できる(): void
    {
        $transaction = Transaction::factory()->create(['amount' => 1000]);

        $response = $this->put(route('transactions.update', $transaction), [
            'transaction_date' => $transaction->transaction_date->format('Y-m-d'),
            'type' => 'expense',
            'category_id' => $transaction->category_id,
            'shop_name' => '更新後の店',
            'amount' => 2500,
        ]);

        $response->assertRedirect(route('transactions.index'));
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'amount' => 2500, 'shop_name' => '更新後の店']);
    }

    public function test_取引を削除できる(): void
    {
        $transaction = Transaction::factory()->create();

        $this->delete(route('transactions.destroy', $transaction))
            ->assertRedirect(route('transactions.index'));

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }

    public function test_月で絞り込める(): void
    {
        Transaction::factory()->create(['transaction_date' => '2026-07-15', 'shop_name' => '7月の店']);
        Transaction::factory()->create(['transaction_date' => '2026-08-15', 'shop_name' => '8月の店']);

        $response = $this->get(route('transactions.index', ['month' => '2026-08']));

        $response->assertOk();
        $response->assertSee('8月の店');
        $response->assertDontSee('7月の店');
    }

    public function test_カテゴリで絞り込める(): void
    {
        $target = Category::factory()->create(['name' => '対象カテゴリ']);
        Transaction::factory()->create(['category_id' => $target->id, 'shop_name' => '対象の店']);
        Transaction::factory()->create(['shop_name' => '対象外の店']);

        $response = $this->get(route('transactions.index', ['category_id' => $target->id]));

        $response->assertOk();
        $response->assertSee('対象の店');
        $response->assertDontSee('対象外の店');
    }
}
