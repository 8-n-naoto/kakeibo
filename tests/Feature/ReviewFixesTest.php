<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ReceiptImage;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Services\AnnualReportService;
use App\Services\ReceiptImageCleaner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * レビューで見つかった不具合の再発防止テスト。
 *
 * どれも「一度は実際に踏んだ／踏みかけた」ものなので、
 * 直したときの意図が分かるようにケース単位で残す。
 */
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // CSV取込はアップロードされたファイルを保存するので、実ディスクを汚さない
        Storage::fake('local');
    }

    // --- month パラメータに配列を渡されても500にしない ---

    public function test_ダッシュボードのmonthに配列を渡しても落ちない(): void
    {
        $this->get('/dashboard?month[]=2026-08')->assertOk();
    }

    public function test_定期支出画面のmonthに配列を渡しても落ちない(): void
    {
        $this->get('/recurring?month[]=2026-08')->assertOk();
    }

    public function test_不正な月の指定は当月として扱う(): void
    {
        $response = $this->get(route('dashboard', ['month' => 'not-a-month']));

        $response->assertOk();
        $response->assertViewHas('currentMonth', fn ($month) => $month->format('Y-m') === now()->format('Y-m'));
    }

    public function test_範囲外の月は当月として扱う(): void
    {
        // Carbon::createFromFormat は "2026-13" を 2027-01 として通してしまうので、
        // 正規表現と範囲チェックで先に弾いていること
        $response = $this->get(route('dashboard', ['month' => '2026-13']));

        $response->assertOk();
        $response->assertViewHas('currentMonth', fn ($month) => $month->format('Y-m') === now()->format('Y-m'));
    }

    public function test_計上は範囲外の月を黙って読み替えずエラーにする(): void
    {
        RecurringTransaction::factory()->create(['name' => '家賃', 'amount' => 85000]);

        $response = $this->from(route('recurring.index'))
            ->post(route('recurring.post'), ['month' => '9999-99']);

        $response->assertSessionHasErrors('month');
        $this->assertSame(0, Transaction::count());
    }

    public function test_artisanコマンドも範囲外の月を弾く(): void
    {
        RecurringTransaction::factory()->create(['name' => '家賃', 'amount' => 85000]);

        $this->artisan('recurring:post', ['--month' => '2026-13'])->assertExitCode(1);

        $this->assertSame(0, Transaction::count());
    }

    public function test_掃除コマンドは0日を受け付けない(): void
    {
        // --days=0 だと、いま解析中のレコードやアップロード直後のファイルを巻き込む
        $this->artisan('receipts:prune', ['--days' => 0])->assertExitCode(1);
    }

    // --- キーワード検索のエスケープ ---

    public function test_パーセント記号は検索語として扱う(): void
    {
        Transaction::factory()->create(['shop_name' => '100%ジュース', 'transaction_date' => '2026-08-01', 'amount' => 200]);
        Transaction::factory()->create(['shop_name' => 'ただの店', 'transaction_date' => '2026-08-02', 'amount' => 300]);

        $response = $this->get(route('transactions.index', ['keyword' => '100%']));

        $response->assertOk();
        $response->assertSee('100%ジュース');
        $response->assertDontSee('ただの店');
    }

    public function test_アンダースコアもワイルドカードにしない(): void
    {
        Transaction::factory()->create(['shop_name' => 'A_B商店', 'transaction_date' => '2026-08-01', 'amount' => 200]);
        Transaction::factory()->create(['shop_name' => 'AXB商店', 'transaction_date' => '2026-08-02', 'amount' => 300]);

        $response = $this->get(route('transactions.index', ['keyword' => 'A_B']));

        $response->assertOk();
        $response->assertSee('A_B商店');
        $response->assertDontSee('AXB商店');
    }

    // --- CSV取込: 行が配列でない入力 ---

    public function test_CSV取込の行が配列でなくても落ちない(): void
    {
        // 確定はセッションとトークンを見るので、実際の流れを通してから壊れた行を送る
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "利用日,利用店名,利用金額\n2026/07/01,店A,100\n");

        $this->post(route('imports.store'), [
            'file' => new UploadedFile($path, 'meisai.csv', 'text/csv', null, true),
        ]);

        $response = $this->from(route('imports.preview'))->post(route('imports.confirm'), [
            'token' => session('csv_import_token'),
            'rows' => ['これは配列ではない'],
        ]);

        // 500 にならず、取り込む行が無い旨のエラーで戻ること
        $response->assertRedirect(route('imports.preview'));
        $this->assertSame(0, Transaction::count());

        @unlink($path);
    }

    // --- レシート画像の掃除: 消してはいけないものを消さない ---

    public function test_解析失敗でも取引が紐づいていれば消さない(): void
    {
        Storage::fake('public');

        $receipt = ReceiptImage::create([
            'path' => UploadedFile::fake()->image('a.jpg')->store('receipts', 'public'),
            'status' => 'failed',
        ]);
        $receipt->forceFill(['created_at' => now()->subDays(90)])->save();

        // 解析に失敗したレシートを、確認画面から手入力で登録したケース
        Transaction::create([
            'transaction_date' => '2026-01-01',
            'type' => 'expense',
            'amount' => 1000,
            'receipt_image_id' => $receipt->id,
        ]);

        $result = app(ReceiptImageCleaner::class)->prune();

        $this->assertSame(0, $result['records']);
        $this->assertDatabaseHas('receipt_images', ['id' => $receipt->id]);
        Storage::disk('public')->assertExists($receipt->path);
    }

    public function test_読み取り待ちのまま放置された画像も猶予後に片付ける(): void
    {
        Storage::fake('public');

        $forgotten = ReceiptImage::create([
            'path' => UploadedFile::fake()->image('forgotten.jpg')->store('receipts', 'public'),
            'status' => 'pending',
        ]);
        $forgotten->forceFill(['created_at' => now()->subDays(60)])->save();

        $result = app(ReceiptImageCleaner::class)->prune();

        $this->assertSame(1, $result['records']);
        $this->assertDatabaseMissing('receipt_images', ['id' => $forgotten->id]);
    }

    public function test_読み取り待ちでも猶予期間内なら残す(): void
    {
        Storage::fake('public');

        // アップロードしてから数日置いて読み取る運用もあるので、すぐには消さない
        $waiting = ReceiptImage::create([
            'path' => UploadedFile::fake()->image('waiting.jpg')->store('receipts', 'public'),
            'status' => 'pending',
        ]);
        $waiting->forceFill(['created_at' => now()->subDays(5)])->save();

        app(ReceiptImageCleaner::class)->prune();

        $this->assertDatabaseHas('receipt_images', ['id' => $waiting->id]);
    }

    // --- 定期支出の二重計上 ---

    public function test_同じ定期支出を同じ月に二度計上できない(): void
    {
        $rule = RecurringTransaction::factory()->create(['name' => '家賃', 'amount' => 85000, 'day_of_month' => 27]);

        Transaction::create([
            'transaction_date' => '2026-08-27',
            'type' => 'expense',
            'amount' => 85000,
            'recurring_transaction_id' => $rule->id,
        ]);

        // DBの一意制約で弾かれること（アプリのチェックをすり抜けても二重にならない）
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        Transaction::create([
            'transaction_date' => '2026-08-27',
            'type' => 'expense',
            'amount' => 85000,
            'recurring_transaction_id' => $rule->id,
        ]);
    }

    public function test_定期支出に紐づかない取引は同日同額でも登録できる(): void
    {
        Transaction::create(['transaction_date' => '2026-08-27', 'type' => 'expense', 'amount' => 500]);
        Transaction::create(['transaction_date' => '2026-08-27', 'type' => 'expense', 'amount' => 500]);

        $this->assertSame(2, Transaction::count());
    }

    // --- 壊れた解析結果でも画面が開ける ---

    public function test_解析結果に配列が混ざっていても確認画面が開ける(): void
    {
        Category::factory()->create(['name' => '食費']);

        $receipt = ReceiptImage::create([
            'path' => 'receipts/dummy.jpg',
            'status' => 'processed',
            'parsed_data' => [
                // AI が想定外の型を返したケース
                'shop_name' => ['name' => 'スーパー'],
                'memo' => ['A', 'B'],
                'transaction_date' => '2026/08/01',
                'total_amount' => '3,200',
                'type' => 'expense',
                'items' => 'これは配列ではない',
            ],
        ]);

        $response = $this->get(route('receipts.confirm', $receipt));

        $response->assertOk();
        $response->assertSee('value="2026-08-01"', false);
    }

    public function test_品目の中身が壊れていても確認画面が開ける(): void
    {
        Category::factory()->create(['name' => '食費']);

        $receipt = ReceiptImage::create([
            'path' => 'receipts/dummy.jpg',
            'status' => 'processed',
            'parsed_data' => [
                'transaction_date' => '2026-08-01',
                'shop_name' => 'スーパー',
                'total_amount' => 1000,
                'type' => 'expense',
                'items' => [
                    // name が配列（旧データでありうる）
                    ['name' => ['牛乳'], 'amount' => 200],
                    // 要素そのものが文字列
                    '牛乳 200円',
                    // まともな品目
                    ['name' => 'パン', 'amount' => '1,200'],
                ],
            ],
        ]);

        $response = $this->get(route('receipts.confirm', $receipt));

        $response->assertOk();
        // 壊れた品目は落とし、まともな品目だけ残ること
        $response->assertSee('value="パン"', false);
        $response->assertSee('value="1200"', false);
        $response->assertDontSee('value="牛乳"', false);
    }

    public function test_壊れた解析結果が1件あっても未登録レシート一覧が開ける(): void
    {
        Storage::fake('public');
        Category::factory()->create(['name' => '食費']);

        ReceiptImage::create([
            'path' => 'receipts/broken.jpg',
            'status' => 'processed',
            'parsed_data' => ['shop_name' => ['x' => 'y'], 'items' => 'not-an-array'],
        ]);

        ReceiptImage::create([
            'path' => 'receipts/ok.jpg',
            'status' => 'processed',
            'parsed_data' => ['shop_name' => 'まいばすけっと', 'total_amount' => 500, 'items' => []],
        ]);

        $response = $this->get(route('receipts.pending'));

        $response->assertOk();
        $response->assertSee('まいばすけっと');
    }

    // --- 年間レポート ---

    public function test_取引の無い年を直接開いても年セレクタと見出しが一致する(): void
    {
        Transaction::factory()->create(['transaction_date' => '2026-08-01', 'amount' => 1000]);

        $response = $this->get('/reports/2001');

        $response->assertOk();
        $response->assertViewHas('availableYears', fn (array $years) => in_array(2001, $years, true));
    }

    public function test_取引が無くても年の選択肢は空にならない(): void
    {
        $years = app(AnnualReportService::class)->availableYears();

        $this->assertSame([(int) now()->format('Y')], $years);
    }

    public function test_年の選択肢は最古の取引から今年までを並べる(): void
    {
        Transaction::factory()->create(['transaction_date' => '2024-03-01', 'amount' => 1000]);
        Transaction::factory()->create(['transaction_date' => '2026-03-01', 'amount' => 1000]);

        $years = app(AnnualReportService::class)->availableYears();

        $this->assertSame([2026, 2025, 2024], array_slice($years, 0, 3));
    }
}
