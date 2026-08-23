<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TransactionImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $contents, string $name = 'meisai.csv', ?string $encoding = null): UploadedFile
    {
        if ($encoding !== null) {
            $contents = mb_convert_encoding($contents, $encoding, 'UTF-8');
        }

        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    public function test_取込画面が表示できる(): void
    {
        $this->get(route('imports.create'))->assertOk();
    }

    public function test_CSVをアップロードするとプレビューに遷移する(): void
    {
        $csv = "利用日,利用店名,利用金額\n2026/08/01,スーパーライフ,3200\n2026/08/03,JR東日本,540\n";

        $response = $this->post(route('imports.store'), ['file' => $this->csv($csv)]);

        $response->assertRedirect(route('imports.preview'));
        $response->assertSessionHas('csv_import_rows');

        $preview = $this->get(route('imports.preview'));
        $preview->assertOk();
        $preview->assertSee('スーパーライフ');
        $preview->assertSee('JR東日本');
    }

    public function test_Shift_JISのCSVも読み込める(): void
    {
        $csv = "利用日,利用店名,利用金額\n2026/08/01,セブンイレブン,780\n";

        $this->post(route('imports.store'), ['file' => $this->csv($csv, 'sjis.csv', 'SJIS-win')])
            ->assertRedirect(route('imports.preview'));

        $rows = session('csv_import_rows');

        $this->assertCount(1, $rows);
        $this->assertSame('セブンイレブン', $rows[0]['shop_name']);
        $this->assertSame(780, $rows[0]['amount']);
    }

    public function test_店名からカテゴリが推測される(): void
    {
        Category::factory()->create(['name' => '交通費']);
        $csv = "利用日,利用店名,利用金額\n2026/08/03,JR東日本 新宿,540\n";

        $this->post(route('imports.store'), ['file' => $this->csv($csv)]);

        $rows = session('csv_import_rows');
        $this->assertSame(Category::where('name', '交通費')->value('id'), $rows[0]['category_id']);
    }

    public function test_マイナス金額は収入として扱われる(): void
    {
        $csv = "利用日,利用店名,利用金額\n2026/08/05,返金 ショップA,-1500\n";

        $this->post(route('imports.store'), ['file' => $this->csv($csv)]);

        $rows = session('csv_import_rows');
        $this->assertSame('income', $rows[0]['type']);
        $this->assertSame(1500, $rows[0]['amount']);
    }

    public function test_既に登録済みの取引は重複候補としてマークされる(): void
    {
        Transaction::factory()->create([
            'transaction_date' => '2026-08-01',
            'amount' => 3200,
            'shop_name' => 'スーパーライフ',
        ]);

        $csv = "利用日,利用店名,利用金額\n2026/08/01,スーパーライフ,3200\n2026/08/02,別の店,1000\n";

        $this->post(route('imports.store'), ['file' => $this->csv($csv)]);

        $rows = session('csv_import_rows');
        $this->assertTrue($rows[0]['duplicate']);
        $this->assertFalse($rows[1]['duplicate']);
    }

    public function test_日付が読み取れない行はエラーになる(): void
    {
        $csv = "利用日,利用店名,利用金額\n未確定,店A,1000\n";

        $this->post(route('imports.store'), ['file' => $this->csv($csv)]);

        $rows = session('csv_import_rows');
        $this->assertFalse($rows[0]['importable']);
        $this->assertNotNull($rows[0]['error']);
    }

    public function test_選択した行だけが取引として登録される(): void
    {
        $category = Category::factory()->create(['name' => '食費']);

        $response = $this->post(route('imports.confirm'), [
            'rows' => [
                ['import' => '1', 'transaction_date' => '2026-08-01', 'type' => 'expense', 'category_id' => $category->id, 'shop_name' => 'スーパーA', 'amount' => 3200],
                ['transaction_date' => '2026-08-02', 'type' => 'expense', 'category_id' => null, 'shop_name' => '取り込まない店', 'amount' => 1000],
            ],
        ]);

        $response->assertRedirect(route('transactions.index'));
        $this->assertSame(1, Transaction::count());
        $this->assertDatabaseHas('transactions', ['shop_name' => 'スーパーA', 'amount' => 3200, 'category_id' => $category->id]);
        $this->assertDatabaseMissing('transactions', ['shop_name' => '取り込まない店']);
    }

    public function test_1行も選択されていない場合はエラーになる(): void
    {
        $response = $this->from(route('imports.preview'))->post(route('imports.confirm'), [
            'rows' => [
                ['transaction_date' => '2026-08-02', 'type' => 'expense', 'amount' => 1000],
            ],
        ]);

        $response->assertSessionHasErrors('rows');
        $this->assertSame(0, Transaction::count());
    }

    public function test_データが無い状態でプレビューを開くと取込画面に戻される(): void
    {
        $this->get(route('imports.preview'))->assertRedirect(route('imports.create'));
    }
}
