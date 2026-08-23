<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ReceiptImage;
use App\Models\Transaction;
use App\Services\ReceiptParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReceiptUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_アップロード画面が表示できる(): void
    {
        $this->get(route('receipts.create'))->assertOk();
    }

    public function test_レシート画像を解析して確認画面に遷移する(): void
    {
        Storage::fake('public');
        Category::factory()->create(['name' => '食費']);

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) {
            $mock->shouldReceive('parse')->once()->andReturn([
                'transaction_date' => '2026-08-01',
                'shop_name' => 'スーパーライフ',
                'total_amount' => 3200,
                'type' => 'expense',
                'suggested_category' => '食費',
                'items' => [['name' => '牛乳', 'amount' => 200]],
                'memo' => null,
                'raw_response' => ['id' => 'interaction_test'],
            ]);
        }));

        $response = $this->post(route('receipts.store'), [
            'image' => UploadedFile::fake()->image('receipt.jpg'),
        ]);

        $receipt = ReceiptImage::firstOrFail();

        $response->assertRedirect(route('receipts.confirm', $receipt));
        $this->assertSame('processed', $receipt->status);
        Storage::disk('public')->assertExists($receipt->path);
    }

    public function test_確認画面に解析結果と提案カテゴリが渡される(): void
    {
        $category = Category::factory()->create(['name' => '食費']);
        $receipt = ReceiptImage::create(['path' => 'receipts/dummy.jpg', 'status' => 'processed']);

        $response = $this->withSession(['parsed' => [
            'transaction_date' => '2026-08-01',
            'shop_name' => 'スーパーライフ',
            'total_amount' => 3200,
            'type' => 'expense',
            'suggested_category' => '食費',
            'items' => [],
            'memo' => null,
        ]])->get(route('receipts.confirm', $receipt));

        $response->assertOk();
        $response->assertViewHas('suggestedCategoryId', $category->id);
        $response->assertSee('スーパーライフ');
    }

    public function test_解析に失敗した場合はエラーが表示される(): void
    {
        Storage::fake('public');

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) {
            $mock->shouldReceive('parse')->andThrow(new RuntimeException('APIエラー'));
        }));

        $response = $this->from(route('receipts.create'))->post(route('receipts.store'), [
            'image' => UploadedFile::fake()->image('receipt.jpg'),
        ]);

        $response->assertRedirect(route('receipts.create'));
        $response->assertSessionHasErrors('image');
        $this->assertDatabaseHas('receipt_images', ['status' => 'failed']);
    }

    public function test_画像以外はアップロードできない(): void
    {
        Storage::fake('public');

        $response = $this->from(route('receipts.create'))->post(route('receipts.store'), [
            'image' => UploadedFile::fake()->create('note.txt', 10),
        ]);

        $response->assertSessionHasErrors('image');
        $this->assertSame(0, ReceiptImage::count());
    }

    public function test_確認画面から取引を登録できる(): void
    {
        $category = Category::factory()->create(['name' => '食費']);
        $receipt = ReceiptImage::create(['path' => 'receipts/dummy.jpg', 'status' => 'processed']);

        $response = $this->post(route('receipts.confirm.store', $receipt), [
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'category_id' => $category->id,
            'shop_name' => 'スーパーライフ',
            'amount' => 3200,
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('transactions', [
            'receipt_image_id' => $receipt->id,
            'amount' => 3200,
            'shop_name' => 'スーパーライフ',
        ]);
        $this->assertSame(1, Transaction::count());
    }
}
