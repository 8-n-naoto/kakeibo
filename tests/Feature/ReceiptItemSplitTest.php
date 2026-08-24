<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ReceiptImage;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1枚のレシートを品目ごとに分けて登録する。
 * スーパーで食費と日用品を一緒に買ったときにカテゴリを分けられるようにするための機能。
 */
class ReceiptItemSplitTest extends TestCase
{
    use RefreshDatabase;

    private function receiptWithItems(?int $total = 1500): ReceiptImage
    {
        return ReceiptImage::create([
            'path' => 'receipts/dummy.jpg',
            'status' => 'processed',
            'parsed_data' => [
                'transaction_date' => '2026-08-01',
                'shop_name' => 'スーパーライフ',
                'total_amount' => $total,
                'type' => 'expense',
                'suggested_category' => '食費',
                'items' => [
                    ['name' => '牛乳', 'amount' => 200],
                    ['name' => 'ティッシュ', 'amount' => 300],
                    ['name' => '弁当', 'amount' => 1000],
                ],
                'memo' => null,
            ],
        ]);
    }

    public function test_確認画面に品目の入力欄が並ぶ(): void
    {
        Category::factory()->create(['name' => '食費']);
        $receipt = $this->receiptWithItems();

        $response = $this->get(route('receipts.confirm', $receipt));

        $response->assertOk();
        $response->assertSee('品目ごとに分ける');
        $response->assertSee('items[0][amount]', false);
        $response->assertSee('ティッシュ');
    }

    public function test_品目ごとにカテゴリを分けて登録できる(): void
    {
        $food = Category::factory()->create(['name' => '食費']);
        $daily = Category::factory()->create(['name' => '日用品']);
        $receipt = $this->receiptWithItems();

        $response = $this->post(route('receipts.confirm.store', $receipt), [
            'mode' => 'items',
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'shop_name' => 'スーパーライフ',
            'items' => [
                ['selected' => '1', 'name' => '牛乳', 'amount' => '200', 'category_id' => $food->id],
                ['selected' => '1', 'name' => 'ティッシュ', 'amount' => '300', 'category_id' => $daily->id],
                ['selected' => '1', 'name' => '弁当', 'amount' => '1000', 'category_id' => $food->id],
            ],
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertSame(3, Transaction::count());

        $this->assertDatabaseHas('transactions', [
            'receipt_image_id' => $receipt->id,
            'memo' => 'ティッシュ',
            'amount' => 300,
            'category_id' => $daily->id,
            'shop_name' => 'スーパーライフ',
        ]);
        $this->assertSame(1200, (int) Transaction::where('category_id', $food->id)->sum('amount'));
    }

    public function test_チェックを外した品目は登録されない(): void
    {
        $food = Category::factory()->create(['name' => '食費']);
        $receipt = $this->receiptWithItems();

        $this->post(route('receipts.confirm.store', $receipt), [
            'mode' => 'items',
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'shop_name' => 'スーパーライフ',
            'items' => [
                ['selected' => '1', 'name' => '牛乳', 'amount' => '200', 'category_id' => $food->id],
                ['name' => 'ティッシュ', 'amount' => '300', 'category_id' => $food->id],
                ['selected' => '1', 'name' => '弁当', 'amount' => '1000', 'category_id' => $food->id],
            ],
        ]);

        $this->assertSame(2, Transaction::count());
        $this->assertDatabaseMissing('transactions', ['memo' => 'ティッシュ']);
    }

    public function test_1件も選ばないとエラーになる(): void
    {
        $receipt = $this->receiptWithItems();

        $response = $this->from(route('receipts.confirm', $receipt))
            ->post(route('receipts.confirm.store', $receipt), [
                'mode' => 'items',
                'transaction_date' => '2026-08-01',
                'type' => 'expense',
                'items' => [
                    ['name' => '牛乳', 'amount' => '200'],
                ],
            ]);

        $response->assertRedirect(route('receipts.confirm', $receipt));
        $response->assertSessionHasErrors('items');
        $this->assertSame(0, Transaction::count());
    }

    public function test_選んだ品目の金額が空だとエラーになる(): void
    {
        $receipt = $this->receiptWithItems();

        $response = $this->from(route('receipts.confirm', $receipt))
            ->post(route('receipts.confirm.store', $receipt), [
                'mode' => 'items',
                'transaction_date' => '2026-08-01',
                'type' => 'expense',
                'items' => [
                    ['selected' => '1', 'name' => '牛乳', 'amount' => ''],
                ],
            ]);

        $response->assertSessionHasErrors('items');
        $this->assertSame(0, Transaction::count());
    }

    public function test_レシート合計とずれたら差額を知らせる(): void
    {
        $food = Category::factory()->create(['name' => '食費']);
        $receipt = $this->receiptWithItems(1500);

        $response = $this->post(route('receipts.confirm.store', $receipt), [
            'mode' => 'items',
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'items' => [
                ['selected' => '1', 'name' => '牛乳', 'amount' => '200', 'category_id' => $food->id],
            ],
        ]);

        $response->assertSessionHas('status', fn (string $status) => str_contains($status, '差は -¥1,300'));
    }

    public function test_合計が一致していれば差額の案内は出ない(): void
    {
        $food = Category::factory()->create(['name' => '食費']);
        $receipt = $this->receiptWithItems(1500);

        $response = $this->post(route('receipts.confirm.store', $receipt), [
            'mode' => 'items',
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'items' => [
                ['selected' => '1', 'name' => '牛乳', 'amount' => '200', 'category_id' => $food->id],
                ['selected' => '1', 'name' => 'ティッシュ', 'amount' => '300', 'category_id' => $food->id],
                ['selected' => '1', 'name' => '弁当', 'amount' => '1000', 'category_id' => $food->id],
            ],
        ]);

        $response->assertSessionHas('status', fn (string $status) => ! str_contains($status, '差は'));
    }

    public function test_品目登録後は二重登録できない(): void
    {
        $food = Category::factory()->create(['name' => '食費']);
        $receipt = $this->receiptWithItems();

        $payload = [
            'mode' => 'items',
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'items' => [
                ['selected' => '1', 'name' => '牛乳', 'amount' => '200', 'category_id' => $food->id],
            ],
        ];

        $this->post(route('receipts.confirm.store', $receipt), $payload);
        $second = $this->post(route('receipts.confirm.store', $receipt), $payload);

        $second->assertRedirect(route('transactions.index'));
        $this->assertSame(1, Transaction::count());
    }

    public function test_品目が無いレシートは従来どおり合計で登録する(): void
    {
        $food = Category::factory()->create(['name' => '食費']);
        $receipt = ReceiptImage::create([
            'path' => 'receipts/dummy.jpg',
            'status' => 'processed',
            'parsed_data' => [
                'transaction_date' => '2026-08-01',
                'shop_name' => '自販機',
                'total_amount' => 160,
                'type' => 'expense',
                'items' => [],
            ],
        ]);

        $response = $this->post(route('receipts.confirm.store', $receipt), [
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'category_id' => $food->id,
            'shop_name' => '自販機',
            'amount' => 160,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('transactions', ['amount' => 160, 'shop_name' => '自販機']);
    }
}
