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

    public function test_エンゲル係数の対象カテゴリとして保存できる(): void
    {
        $this->post(route('categories.store'), [
            'name' => '食料品',
            'type' => 'expense',
            'expense_nature' => 'variable',
            'counts_as_food' => '1',
            'color' => '#123456',
            'sort_order' => 1,
        ]);

        $this->assertDatabaseHas('categories', ['name' => '食料品', 'counts_as_food' => true]);
    }

    public function test_チェックを外すと対象から外れる(): void
    {
        $category = Category::factory()->create(['name' => '食費', 'counts_as_food' => true]);

        $this->put(route('categories.update', $category), [
            'name' => '食費',
            'type' => 'expense',
            'expense_nature' => 'variable',
            'counts_as_food' => '0',
            'color' => '#123456',
            'sort_order' => 1,
        ]);

        $this->assertFalse($category->fresh()->counts_as_food);
    }

    public function test_収入カテゴリは食料費の対象にならない(): void
    {
        $this->post(route('categories.store'), [
            'name' => 'ボーナス2',
            'type' => 'income',
            'counts_as_food' => '1',
            'color' => '#123456',
            'sort_order' => 5,
        ]);

        $this->assertDatabaseHas('categories', ['name' => 'ボーナス2', 'counts_as_food' => false]);
    }

    public function test_カテゴリを削除すると取引は未分類になる(): void
    {
        $category = Category::factory()->create();
        $transaction = Transaction::factory()->create(['category_id' => $category->id]);

        $this->delete(route('categories.destroy', $category), [
            'expected_impact' => $this->impactOf($category),
        ])->assertRedirect(route('categories.index'));

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertNull($transaction->fresh()->category_id);
    }

    public function test_一覧に巻き添えの件数が出る(): void
    {
        // 予算と学習ルールは cascadeOnDelete。押す前に件数が見えていないと、
        // 1クリックで数年分の予算設定と学習が消える
        $category = Category::factory()->create(['name' => '食費']);
        Transaction::factory()->create(['category_id' => $category->id]);
        \App\Models\Budget::factory()->create(['category_id' => $category->id]);

        $response = $this->get(route('categories.index'));

        $response->assertOk();
        $response->assertSee('予算と自動分類ルールは一緒に削除されます');
        $response->assertSee('name="expected_impact"', false);
    }

    public function test_画面の表示が古いと削除しない(): void
    {
        // 開いたときは0件だったのに、実は数年分の予算があった、を起こさない
        $category = Category::factory()->create();
        \App\Models\Budget::factory()->create(['category_id' => $category->id]);

        $response = $this->from(route('categories.index'))->delete(
            route('categories.destroy', $category),
            ['expected_impact' => '0-0'],
        );

        $response->assertSessionHasErrors('category');
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_取引が増えただけなら削除できる(): void
    {
        // 突き合わせるのは「消えるもの」だけ。取引や定期支出は未分類になるだけなので、
        // 取込が走っただけで何度読み直しても削除できない、という状態にはしない
        $category = Category::factory()->create();
        $signature = $this->impactOf($category);

        // 画面を開いたあとに取引が増えた
        Transaction::factory()->create(['category_id' => $category->id]);

        $this->delete(route('categories.destroy', $category), [
            'expected_impact' => $signature,
        ])->assertRedirect(route('categories.index'));

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_収入カテゴリには予算とルールの列を出さない(): void
    {
        // どちらも支出カテゴリにしか付かない。0件の赤い警告を毎回出すと、
        // 本当に効く側まで読み飛ばされる
        Category::factory()->create(['name' => '給与', 'type' => 'income']);

        $response = $this->get(route('categories.index'));

        $response->assertOk();
        // 支出側には出る
        $response->assertSee('予算と自動分類ルールは一緒に削除されます');
        // 収入の表には「予算」「ルール」の列ヘッダーが無い
        $this->assertSame(1, substr_count($response->getContent(), '>予算</th>'));
    }

    public function test_削除の結果に巻き添えの件数を出す(): void
    {
        $category = Category::factory()->create(['name' => '食費']);
        \App\Models\Budget::factory()->create(['category_id' => $category->id]);

        $this->delete(route('categories.destroy', $category), [
            'expected_impact' => $this->impactOf($category),
        ]);

        $this->assertStringContainsString('1件の予算を削除しました', (string) session('status'));
    }

    private function impactOf(Category $category): string
    {
        return \App\Http\Controllers\CategoryController::impactSignature(
            \App\Http\Controllers\CategoryController::impactOf($category),
        );
    }
}
