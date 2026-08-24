<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CSV取込1回ぶんの記録と、まとめての取り消し。
 *
 * 「取り消せる」と分かっていることが、取り込むこと自体の心理的な負担を下げる。
 * 月1でまとめて取り込む運用だと、間違いに気づくのが何ヶ月も後になる。
 */
class ImportBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 列の対応をやり直せるようアップロードしたCSVを保存するので、実ディスクを汚さない
        Storage::fake('local');

        Carbon::setTestNow(Carbon::create(2026, 8, 24, 5, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function csv(string $contents, string $name = 'rakuten.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    /**
     * アップロード → プレビュー → 全行を登録、までを通す。
     */
    private function importAll(string $contents, string $name = 'rakuten.csv'): void
    {
        $this->post(route('imports.store'), ['file' => $this->csv($contents, $name)]);

        $rows = [];

        foreach (session('csv_import_rows') as $i => $row) {
            $rows[$i] = [
                'import' => '1',
                'transaction_date' => $row['transaction_date'],
                'type' => $row['type'],
                'category_id' => $row['category_id'],
                'shop_name' => $row['shop_name'],
                'amount' => $row['amount'],
            ];
        }

        $this->post(route('imports.confirm'), [
            'token' => session('csv_import_token'),
            'rows' => $rows,
        ]);
    }

    public function test_取込を1回ぶんの記録として残す(): void
    {
        $this->importAll("利用日,利用店名,利用金額\n2026/07/01,スーパーA,3200\n2026/07/20,店B,1000\n2026/07/10,返金,-500\n");

        $batch = ImportBatch::firstOrFail();

        $this->assertSame('rakuten.csv', $batch->file_name);
        $this->assertSame(3, $batch->row_count);
        // 差引＝支出 4,200 − 収入 500
        $this->assertSame(3700, $batch->total_amount);
        $this->assertSame('2026-07-01', $batch->first_date->toDateString());
        $this->assertSame('2026-07-20', $batch->last_date->toDateString());
        $this->assertSame(3, Transaction::where('import_batch_id', $batch->id)->count());
    }

    public function test_取込履歴が表示できる(): void
    {
        $this->importAll("利用日,利用店名,利用金額\n2026/07/01,スーパーA,3200\n");

        $this->get(route('imports.batches'))
            ->assertOk()
            ->assertSee('rakuten.csv')
            ->assertSee('1件を取り消す');
    }

    public function test_取込をまとめて取り消せる(): void
    {
        $this->importAll("利用日,利用店名,利用金額\n2026/07/01,スーパーA,3200\n2026/07/02,店B,1000\n");

        // 取込と関係のない取引は消さない
        $unrelated = Transaction::factory()->create(['shop_name' => '手入力の店', 'amount' => 999]);

        $batch = ImportBatch::firstOrFail();

        $this->delete(route('imports.batches.destroy', $batch))
            ->assertRedirect(route('imports.batches'));

        $this->assertSame(1, Transaction::count());
        $this->assertNotNull($unrelated->fresh());
        // 記録自体は履歴として残す
        $this->assertNotNull($batch->fresh());
    }

    public function test_取込後に手で直した取引は取り消しても残す(): void
    {
        $this->importAll("利用日,利用店名,利用金額\n2026/07/01,スーパーA,3200\n2026/07/02,店B,1000\n");

        $edited = Transaction::where('shop_name', 'スーパーA')->firstOrFail();

        // 値が実際に変わらないと isDirty() が false になり、updated_at が動かない。
        // あわせて時計も進めないと created_at と同じ秒のままになる
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 5, 0, 30));
        $edited->update(['amount' => 4000]);

        $this->assertTrue($edited->fresh()->updated_at->greaterThan($edited->fresh()->created_at));

        $batch = ImportBatch::firstOrFail();

        $response = $this->delete(route('imports.batches.destroy', $batch));

        $this->assertSame(1, Transaction::count());
        $this->assertNotNull($edited->fresh());
        $this->assertStringContainsString('手で直した1件は残しました', (string) session('status'));
        $response->assertRedirect(route('imports.batches'));
    }

    public function test_取り消し済みの取込をもう一度取り消しても壊れない(): void
    {
        $this->importAll("利用日,利用店名,利用金額\n2026/07/01,スーパーA,3200\n");

        $batch = ImportBatch::firstOrFail();

        $this->delete(route('imports.batches.destroy', $batch));
        $this->delete(route('imports.batches.destroy', $batch))
            ->assertRedirect(route('imports.batches'));

        $this->assertSame(0, Transaction::count());
        $this->assertStringContainsString('もう残っていません', (string) session('status'));
    }

    public function test_1件も登録されなければ取込の記録を残さない(): void
    {
        Transaction::factory()->create([
            'transaction_date' => '2026-07-01',
            'type' => 'expense',
            'amount' => 3200,
            'shop_name' => 'スーパーA',
        ]);

        // 唯一の行が確定時の重複チェックで見送られる
        $this->post(route('imports.store'), ['file' => $this->csv("利用日,利用店名,利用金額\n2026/07/01,スーパーA,3200\n")]);

        $rows = session('csv_import_rows');

        $this->post(route('imports.confirm'), [
            'token' => session('csv_import_token'),
            'rows' => [[
                'import' => '1',
                'transaction_date' => $rows[0]['transaction_date'],
                'type' => $rows[0]['type'],
                'category_id' => null,
                'shop_name' => $rows[0]['shop_name'],
                'amount' => $rows[0]['amount'],
            ]],
        ]);

        $this->assertSame(0, ImportBatch::count());
        $this->assertSame(1, Transaction::count());
    }

    public function test_取込の記録を消しても取引は消えない(): void
    {
        $this->importAll("利用日,利用店名,利用金額\n2026/07/01,スーパーA,3200\n");

        $batch = ImportBatch::firstOrFail();
        $batch->delete();

        // 記録を消したくらいで家計データが消えては困る
        $this->assertSame(1, Transaction::count());
        $this->assertNull(Transaction::first()->import_batch_id);
    }

    public function test_未ログインでは取込履歴を触れない(): void
    {
        $this->importAll("利用日,利用店名,利用金額\n2026/07/01,スーパーA,3200\n");

        $batch = ImportBatch::firstOrFail();

        \Illuminate\Support\Facades\Auth::logout();

        $this->get(route('imports.batches'))->assertRedirect('/login');
        $this->delete(route('imports.batches.destroy', $batch))->assertRedirect('/login');

        $this->assertSame(1, Transaction::count());
    }
}
