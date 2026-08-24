<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 取引一覧の検索・絞り込みと、絞り込み条件を引き継いだCSV出力。
 */
class TransactionFilterTest extends TestCase
{
    use RefreshDatabase;

    private function seedTransactions(): void
    {
        Transaction::factory()->create([
            'transaction_date' => '2026-08-05',
            'type' => 'expense',
            'shop_name' => 'まいばすけっと 中野店',
            'memo' => null,
            'amount' => 1200,
        ]);

        Transaction::factory()->create([
            'transaction_date' => '2026-08-20',
            'type' => 'expense',
            'shop_name' => 'ヨドバシカメラ',
            'memo' => 'キーボード',
            'amount' => 15000,
        ]);

        Transaction::factory()->create([
            'transaction_date' => '2026-07-25',
            'type' => 'income',
            'shop_name' => '勤務先',
            'memo' => null,
            'amount' => 300000,
        ]);
    }

    public function test_キーワードで店名を検索できる(): void
    {
        $this->seedTransactions();

        $response = $this->get(route('transactions.index', ['keyword' => 'まいばす']));

        $response->assertOk();
        $response->assertSee('まいばすけっと 中野店');
        $response->assertDontSee('ヨドバシカメラ');
    }

    public function test_キーワードでメモも検索できる(): void
    {
        $this->seedTransactions();

        $response = $this->get(route('transactions.index', ['keyword' => 'キーボード']));

        $response->assertOk();
        $response->assertSee('ヨドバシカメラ');
        $response->assertDontSee('まいばすけっと');
    }

    public function test_種別で絞り込める(): void
    {
        $this->seedTransactions();

        $response = $this->get(route('transactions.index', ['type' => 'income']));

        $response->assertOk();
        $response->assertSee('勤務先');
        $response->assertDontSee('ヨドバシカメラ');
    }

    public function test_期間で絞り込める(): void
    {
        $this->seedTransactions();

        $response = $this->get(route('transactions.index', [
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-10',
        ]));

        $response->assertOk();
        $response->assertSee('まいばすけっと 中野店');
        $response->assertDontSee('ヨドバシカメラ');
        $response->assertDontSee('勤務先');
    }

    public function test_金額の下限で絞り込める(): void
    {
        $this->seedTransactions();

        $response = $this->get(route('transactions.index', ['amount_min' => 10000, 'type' => 'expense']));

        $response->assertOk();
        $response->assertSee('ヨドバシカメラ');
        $response->assertDontSee('まいばすけっと');
    }

    public function test_絞り込み結果の合計が表示される(): void
    {
        $this->seedTransactions();

        $response = $this->get(route('transactions.index', ['month' => '2026-08']));

        $response->assertOk();
        // 支出 1,200 + 15,000 = 16,200
        $response->assertSee('16,200');
        $response->assertSee('2 件');
    }

    public function test_金額の上限が下限より小さいとエラーになる(): void
    {
        $response = $this->from(route('transactions.index'))->get(route('transactions.index', [
            'amount_min' => 5000,
            'amount_max' => 1000,
        ]));

        $response->assertSessionHasErrors('amount_max');
    }

    public function test_並び順を金額の大きい順にできる(): void
    {
        $this->seedTransactions();

        $response = $this->get(route('transactions.index', ['type' => 'expense', 'sort' => 'amount_desc']));

        $response->assertOk();
        $response->assertSeeInOrder(['ヨドバシカメラ', 'まいばすけっと 中野店']);
    }

    public function test_絞り込み条件がCSV出力にも引き継がれる(): void
    {
        $this->seedTransactions();

        $response = $this->get(route('transactions.export', ['keyword' => 'ヨドバシ']));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('ヨドバシカメラ', $csv);
        $this->assertStringNotContainsString('まいばすけっと', $csv);
    }

    public function test_カテゴリと種別を組み合わせて絞り込める(): void
    {
        $category = Category::factory()->create(['name' => '食費', 'type' => 'expense']);

        Transaction::factory()->create([
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'category_id' => $category->id,
            'shop_name' => '対象の店',
            'amount' => 500,
        ]);

        Transaction::factory()->create([
            'transaction_date' => '2026-08-02',
            'type' => 'expense',
            'category_id' => null,
            'shop_name' => '対象外の店',
            'amount' => 500,
        ]);

        $response = $this->get(route('transactions.index', [
            'category_id' => $category->id,
            'type' => 'expense',
        ]));

        $response->assertOk();
        $response->assertSee('対象の店');
        $response->assertDontSee('対象外の店');
    }
}
