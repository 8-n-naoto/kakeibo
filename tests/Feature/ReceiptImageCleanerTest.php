<?php

namespace Tests\Feature;

use App\Models\ReceiptImage;
use App\Models\Transaction;
use App\Services\ReceiptImageCleaner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 使われていないレシート画像の掃除。
 */
class ReceiptImageCleanerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function cleaner(): ReceiptImageCleaner
    {
        return app(ReceiptImageCleaner::class);
    }

    private function putImage(string $name): string
    {
        return UploadedFile::fake()->image($name)->store('receipts', 'public');
    }

    private function receipt(string $status, int $daysAgo, ?string $name = null): ReceiptImage
    {
        $receipt = ReceiptImage::create([
            'path' => $this->putImage($name ?? uniqid('r').'.jpg'),
            'status' => $status,
        ]);

        $receipt->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $receipt;
    }

    public function test_解析に失敗した古い画像は削除される(): void
    {
        $receipt = $this->receipt('failed', 40);

        $result = $this->cleaner()->prune();

        $this->assertSame(1, $result['records']);
        $this->assertSame(1, $result['files']);
        $this->assertDatabaseMissing('receipt_images', ['id' => $receipt->id]);
        Storage::disk('public')->assertMissing($receipt->path);
    }

    public function test_猶予期間内の画像は残る(): void
    {
        $receipt = $this->receipt('failed', 3);

        $result = $this->cleaner()->prune();

        $this->assertSame(0, $result['records']);
        $this->assertDatabaseHas('receipt_images', ['id' => $receipt->id]);
        Storage::disk('public')->assertExists($receipt->path);
    }

    public function test_登録されないまま放置された画像は削除される(): void
    {
        $receipt = $this->receipt('processed', 60);

        $this->cleaner()->prune();

        $this->assertDatabaseMissing('receipt_images', ['id' => $receipt->id]);
    }

    public function test_取引が紐づいている画像は絶対に消さない(): void
    {
        $receipt = $this->receipt('processed', 400);

        Transaction::create([
            'transaction_date' => '2025-01-01',
            'type' => 'expense',
            'amount' => 1000,
            'receipt_image_id' => $receipt->id,
        ]);

        $result = $this->cleaner()->prune();

        $this->assertSame(0, $result['records']);
        $this->assertDatabaseHas('receipt_images', ['id' => $receipt->id]);
        Storage::disk('public')->assertExists($receipt->path);
    }

    public function test_DBに無い孤児ファイルも片付ける(): void
    {
        $orphan = $this->putImage('orphan.jpg');
        touch(Storage::disk('public')->path($orphan), now()->subDays(90)->getTimestamp());

        $result = $this->cleaner()->prune();

        $this->assertSame(1, $result['files']);
        Storage::disk('public')->assertMissing($orphan);
    }

    public function test_猶予日数を指定できる(): void
    {
        $this->receipt('failed', 10);

        $this->assertSame(0, $this->cleaner()->prune(30)['records']);
        $this->assertSame(1, $this->cleaner()->prune(7)['records']);
    }

    public function test_プレビューは削除せずに数えるだけ(): void
    {
        $receipt = $this->receipt('failed', 40);

        $preview = $this->cleaner()->preview();

        $this->assertSame(1, $preview['failed']);
        $this->assertGreaterThan(0, $preview['bytes']);
        $this->assertDatabaseHas('receipt_images', ['id' => $receipt->id]);
    }

    public function test_画面のボタンから片付けられる(): void
    {
        $receipt = $this->receipt('failed', 40);

        $response = $this->post(route('receipts.cleanup'));

        $response->assertRedirect(route('receipts.pending'));
        $this->assertDatabaseMissing('receipt_images', ['id' => $receipt->id]);
    }

    public function test_対象が無ければ何もしないと伝える(): void
    {
        $response = $this->post(route('receipts.cleanup'));

        $response->assertRedirect(route('receipts.pending'));
        $response->assertSessionHas('status', fn (string $status) => str_contains($status, '片付ける画像はありませんでした'));
    }

    public function test_artisanコマンドのdry_runでは削除しない(): void
    {
        $receipt = $this->receipt('failed', 40);

        $this->artisan('receipts:prune', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseHas('receipt_images', ['id' => $receipt->id]);
    }

    public function test_artisanコマンドで削除できる(): void
    {
        $receipt = $this->receipt('failed', 40);

        $this->artisan('receipts:prune', ['--days' => 30])->assertExitCode(0);

        $this->assertDatabaseMissing('receipt_images', ['id' => $receipt->id]);
    }
}
