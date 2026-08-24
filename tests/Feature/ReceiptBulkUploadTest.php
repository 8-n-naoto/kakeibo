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

/**
 * 週1〜月1でレシートを溜めてから処理する運用のための、
 * 複数枚アップロード → 一括確認 → 一括登録の流れを検証する。
 */
class ReceiptBulkUploadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function parsedResult(array $overrides = []): array
    {
        return array_merge([
            'transaction_date' => '2026-08-01',
            'shop_name' => 'スーパーライフ',
            'total_amount' => 3200,
            'type' => 'expense',
            'suggested_category' => '食費',
            'items' => [],
            'memo' => null,
            'raw_response' => null,
        ], $overrides);
    }

    public function test_複数枚をまとめてアップロードすると読み取り待ちとして並ぶ(): void
    {
        Storage::fake('public');
        Category::factory()->create(['name' => '食費']);

        $response = $this->post(route('receipts.store'), [
            'images' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
                UploadedFile::fake()->image('c.jpg'),
            ],
        ]);

        $response->assertRedirect(route('receipts.pending', ['autostart' => 1]));
        // アップロード時点ではAIを呼ばないので、全部「読み取り待ち」
        $this->assertSame(3, ReceiptImage::where('status', 'pending')->count());
    }

    public function test_一部の読み取りが失敗しても成功分は一括確認画面に並ぶ(): void
    {
        Storage::fake('public');
        Category::factory()->create(['name' => '食費']);

        $ok = ReceiptImage::create([
            'path' => UploadedFile::fake()->image('ok.jpg')->store('receipts', 'public'),
            'status' => 'pending',
        ]);
        $ng = ReceiptImage::create([
            'path' => UploadedFile::fake()->image('ng.jpg')->store('receipts', 'public'),
            'status' => 'pending',
        ]);

        // 1枚目は成功、2枚目は失敗（読み取りは1枚=1リクエスト）
        $first = $this->parsedResult(['shop_name' => '成功した店']);
        $calls = 0;

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) use ($first, &$calls) {
            $mock->shouldReceive('parse')->twice()->andReturnUsing(function () use ($first, &$calls) {
                $calls++;

                if ($calls === 1) {
                    return $first;
                }

                throw new RuntimeException('APIエラー');
            });
        }));

        $this->postJson(route('receipts.parse', $ok))->assertOk();
        $this->postJson(route('receipts.parse', $ng))->assertStatus(422);

        $response = $this->get(route('receipts.pending'));

        $response->assertOk();
        $response->assertSee('成功した店');
        $this->assertSame(1, ReceiptImage::where('status', 'processed')->count());
        $this->assertSame(1, ReceiptImage::where('status', 'failed')->count());
    }

    public function test_未登録レシートの一覧に解析結果が並ぶ(): void
    {
        // pending 画面は掃除対象の集計でストレージを見るので、実ディスクに触らせない
        Storage::fake('public');
        Category::factory()->create(['name' => '食費']);

        ReceiptImage::create([
            'path' => 'receipts/a.jpg',
            'status' => 'processed',
            'parsed_data' => $this->parsedResult(['shop_name' => 'まいばすけっと']),
        ]);

        $response = $this->get(route('receipts.pending'));

        $response->assertOk();
        $response->assertSee('まいばすけっと');
    }

    public function test_登録済みのレシートは一覧に出ない(): void
    {
        Storage::fake('public');

        $receipt = ReceiptImage::create([
            'path' => 'receipts/a.jpg',
            'status' => 'processed',
            'parsed_data' => $this->parsedResult(['shop_name' => '登録済みの店']),
        ]);

        Transaction::create([
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'amount' => 3200,
            'receipt_image_id' => $receipt->id,
        ]);

        $response = $this->get(route('receipts.pending'));

        $response->assertOk();
        $response->assertDontSee('登録済みの店');
    }

    public function test_チェックしたレシートだけがまとめて登録される(): void
    {
        $category = Category::factory()->create(['name' => '食費']);

        $first = ReceiptImage::create(['path' => 'receipts/a.jpg', 'status' => 'processed', 'parsed_data' => $this->parsedResult()]);
        $second = ReceiptImage::create(['path' => 'receipts/b.jpg', 'status' => 'processed', 'parsed_data' => $this->parsedResult()]);
        $third = ReceiptImage::create(['path' => 'receipts/c.jpg', 'status' => 'processed', 'parsed_data' => $this->parsedResult()]);

        $response = $this->post(route('receipts.pending.store'), [
            'rows' => [
                $first->id => [
                    'selected' => '1',
                    'transaction_date' => '2026-08-01',
                    'type' => 'expense',
                    'category_id' => $category->id,
                    'shop_name' => 'A店',
                    'amount' => '1000',
                ],
                $second->id => [
                    // selected が無いので登録されない
                    'transaction_date' => '2026-08-02',
                    'type' => 'expense',
                    'category_id' => $category->id,
                    'shop_name' => 'B店',
                    'amount' => '2000',
                ],
                $third->id => [
                    'selected' => '1',
                    'transaction_date' => '2026-08-03',
                    'type' => 'expense',
                    'category_id' => '',
                    'shop_name' => 'C店',
                    'amount' => '3000',
                ],
            ],
        ]);

        $response->assertRedirect(route('receipts.pending'));
        $this->assertSame(2, Transaction::count());
        $this->assertDatabaseHas('transactions', ['receipt_image_id' => $first->id, 'amount' => 1000]);
        $this->assertDatabaseMissing('transactions', ['receipt_image_id' => $second->id]);
        $this->assertDatabaseHas('transactions', ['receipt_image_id' => $third->id, 'amount' => 3000, 'category_id' => null]);
    }

    public function test_一括登録を二重送信しても取引は増えない(): void
    {
        $category = Category::factory()->create(['name' => '食費']);
        $receipt = ReceiptImage::create(['path' => 'receipts/a.jpg', 'status' => 'processed', 'parsed_data' => $this->parsedResult()]);

        $payload = [
            'rows' => [
                $receipt->id => [
                    'selected' => '1',
                    'transaction_date' => '2026-08-01',
                    'type' => 'expense',
                    'category_id' => $category->id,
                    'shop_name' => 'A店',
                    'amount' => '1000',
                ],
            ],
        ];

        $this->post(route('receipts.pending.store'), $payload);
        $this->post(route('receipts.pending.store'), $payload);

        $this->assertSame(1, Transaction::count());
    }

    public function test_金額が空のまま登録しようとするとエラーになる(): void
    {
        $receipt = ReceiptImage::create(['path' => 'receipts/a.jpg', 'status' => 'processed', 'parsed_data' => $this->parsedResult()]);

        $response = $this->from(route('receipts.pending'))->post(route('receipts.pending.store'), [
            'rows' => [
                $receipt->id => [
                    'selected' => '1',
                    'transaction_date' => '2026-08-01',
                    'type' => 'expense',
                    'amount' => '',
                ],
            ],
        ]);

        $response->assertRedirect(route('receipts.pending'));
        $response->assertSessionHasErrors('rows.'.$receipt->id.'.amount');
        $this->assertSame(0, Transaction::count());
    }

    public function test_1件も選ばずに登録しようとするとエラーになる(): void
    {
        $response = $this->from(route('receipts.pending'))->post(route('receipts.pending.store'), ['rows' => []]);

        $response->assertRedirect(route('receipts.pending'));
        $response->assertSessionHasErrors('rows');
    }
}
