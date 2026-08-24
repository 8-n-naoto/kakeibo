<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransactionImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 列の対応をやり直せるようアップロードしたCSVを保存するので、実ディスクを汚さない
        Storage::fake('local');

        // 年の無い日付の補完を検証するので「今」を固定する
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 4, 30, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function csv(string $contents, string $name = 'meisai.csv', ?string $encoding = null): UploadedFile
    {
        if ($encoding !== null) {
            $contents = mb_convert_encoding($contents, $encoding, 'UTF-8');
        }

        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function upload(string $contents, string $name = 'meisai.csv', ?string $encoding = null)
    {
        return $this->post(route('imports.store'), ['file' => $this->csv($contents, $name, $encoding)]);
    }

    /**
     * プレビューに出ている行をそのまま送り返す（実際のフォーム送信と同じ形）。
     *
     * @param  array<int, int>  $importIndexes  チェックを入れる行
     * @param  array<int, array<string, mixed>>  $overrides  行ごとの上書き
     */
    private function confirmAll(array $importIndexes, array $overrides = [])
    {
        $rows = [];

        foreach (session('csv_import_rows') as $i => $row) {
            $rows[$i] = array_merge([
                'transaction_date' => $row['transaction_date'],
                'type' => $row['type'],
                'category_id' => $row['category_id'],
                'shop_name' => $row['shop_name'],
                'amount' => $row['amount'],
            ], $overrides[$i] ?? []);

            if (in_array($i, $importIndexes, true)) {
                $rows[$i]['import'] = '1';
            }
        }

        return $this->from(route('imports.preview'))->post(route('imports.confirm'), [
            'token' => session('csv_import_token'),
            'rows' => $rows,
        ]);
    }

    public function test_取込画面が表示できる(): void
    {
        $this->get(route('imports.create'))->assertOk();
    }

    public function test_CSVをアップロードするとプレビューに遷移する(): void
    {
        $csv = "利用日,利用店名,利用金額\n2026/08/01,スーパーライフ,3200\n2026/08/03,JR東日本,540\n";

        $response = $this->upload($csv);

        $response->assertRedirect(route('imports.preview'));
        $response->assertSessionHas('csv_import_rows');

        $preview = $this->get(route('imports.preview'));
        $preview->assertOk();
        $preview->assertSee('スーパーライフ');
        $preview->assertSee('JR東日本');
        // 明細の請求額と突き合わせられるように合計を出す
        $preview->assertSee('¥3,740');
    }

    public function test_Shift_JISのCSVも読み込める(): void
    {
        $csv = "利用日,利用店名,利用金額\n2026/08/01,セブンイレブン,780\n";

        $this->upload($csv, 'sjis.csv', 'SJIS-win')->assertRedirect(route('imports.preview'));

        $rows = session('csv_import_rows');

        $this->assertCount(1, $rows);
        $this->assertSame('セブンイレブン', $rows[0]['shop_name']);
        $this->assertSame(780, $rows[0]['amount']);
    }

    public function test_店名からカテゴリが推測される(): void
    {
        Category::factory()->create(['name' => '交通費']);
        $csv = "利用日,利用店名,利用金額\n2026/08/03,JR東日本 新宿,540\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertSame(Category::where('name', '交通費')->value('id'), $rows[0]['category_id']);
    }

    public function test_マイナス金額は収入として扱われる(): void
    {
        $csv = "利用日,利用店名,利用金額\n2026/08/05,返金 ショップA,-1500\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertSame('income', $rows[0]['type']);
        $this->assertSame(1500, $rows[0]['amount']);
    }

    public function test_既に登録済みの取引は重複候補としてマークされる(): void
    {
        Transaction::factory()->create([
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'amount' => 3200,
            'shop_name' => 'スーパーライフ',
        ]);

        $csv = "利用日,利用店名,利用金額\n2026/08/01,スーパーライフ,3200\n2026/08/02,別の店,1000\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertTrue($rows[0]['duplicate']);
        $this->assertFalse($rows[1]['duplicate']);
        // 何と一致したのか分からないと判断できない
        $this->assertStringContainsString('スーパーライフ', (string) $rows[0]['duplicate_of']);
    }

    public function test_同じ日の返金は購入の重複とみなさない(): void
    {
        Transaction::factory()->create([
            'transaction_date' => '2026-08-05',
            'type' => 'expense',
            'amount' => 3000,
            'shop_name' => 'ショップA',
        ]);

        $csv = "利用日,利用店名,利用金額\n2026/08/05,ショップA,-3000\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertSame('income', $rows[0]['type']);
        $this->assertFalse($rows[0]['duplicate']);
    }

    public function test_店名の全角半角のゆれは重複判定で吸収する(): void
    {
        Transaction::factory()->create([
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'amount' => 3200,
            'shop_name' => 'スーパーライフ',
        ]);

        $csv = "利用日,利用店名,利用金額\n2026/08/01,ｽｰﾊﾟｰﾗｲﾌ,3200\n";

        $this->upload($csv);

        $this->assertTrue(session('csv_import_rows')[0]['duplicate']);
    }

    public function test_日付が読み取れない行はエラーになる(): void
    {
        $csv = "利用日,利用店名,利用金額\n未確定,店A,1000\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertFalse($rows[0]['importable']);
        $this->assertStringContainsString('未確定', (string) $rows[0]['error']);
    }

    public function test_前置き行があってもヘッダーを見つけられる(): void
    {
        $csv = "○○カードご利用代金明細\nお客様番号,1234567890\n利用日,利用店名,利用金額\n2026/08/01,スーパーライフ,3200\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['importable']);
        $this->assertSame('2026-08-01', $rows[0]['transaction_date']);
        $this->assertSame(3200, $rows[0]['amount']);
    }

    public function test_引用符の中に改行があっても1行として読む(): void
    {
        $csv = "利用日,摘要,利用金額\n2026/08/01,\"スーパー\r\n本店\",3200\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertCount(1, $rows);
        $this->assertSame(3200, $rows[0]['amount']);
    }

    public function test_出金と入金が別列の銀行CSVを読める(): void
    {
        $csv = "日付,摘要,お支払金額,お預り金額,差引残高\n2026/07/25,給与,,300000,500000\n2026/07/26,家賃,85000,,415000\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertSame('income', $rows[0]['type']);
        $this->assertSame(300000, $rows[0]['amount']);
        $this->assertSame('expense', $rows[1]['type']);
        $this->assertSame(85000, $rows[1]['amount']);
    }

    public function test_タブ区切りも読める(): void
    {
        $csv = "利用日\t利用店名\t利用金額\n2026/08/01\tスーパー\t3200\n";

        $this->upload($csv, 'meisai.tsv');

        $rows = session('csv_import_rows');
        $this->assertSame(3200, $rows[0]['amount']);
    }

    public function test_小数のついた金額を100倍にしない(): void
    {
        $csv = "利用日,利用店名,利用金額\n2026/08/01,アマゾン,1234.00\n";

        $this->upload($csv);

        // 数字だけ拾う実装だと 123400 になり、気づかないまま100倍が登録される
        $this->assertSame(1234, session('csv_import_rows')[0]['amount']);
    }

    public function test_読み取れない金額はでっち上げずにエラーにする(): void
    {
        $csv = "利用日,利用店名,利用金額\n2026/08/01,店A,\"1,234 (内消費税112円)\"\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertFalse($rows[0]['importable']);
        $this->assertStringContainsString('内消費税', (string) $rows[0]['error']);
    }

    public function test_年の無い日付は同じファイルの他の行から補う(): void
    {
        $csv = "利用日,利用店名,利用金額\n12/25,店A,1000\n2025/12/26,店B,2000\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertSame('2025-12-25', $rows[0]['transaction_date']);
        $this->assertTrue($rows[0]['assumed_year']);
        $this->assertFalse($rows[1]['assumed_year']);
    }

    public function test_年の無い日付だけなら未来にならない年を選ぶ(): void
    {
        // 12月分の明細を8月に取り込んでも、来年の12月にはしない
        $csv = "利用日,利用店名,利用金額\n12/25,店A,1000\n";

        $this->upload($csv);

        $this->assertSame('2025-12-25', session('csv_import_rows')[0]['transaction_date']);
    }

    public function test_行数が多すぎるCSVは受け付けない(): void
    {
        $csv = "利用日,利用店名,利用金額\n".str_repeat("2026/08/01,店,100\n", CsvImportService::MAX_ROWS + 10);

        $this->from(route('imports.create'))
            ->post(route('imports.store'), ['file' => $this->csv($csv)])
            ->assertSessionHasErrors('file');
    }

    public function test_選択した行だけが取引として登録される(): void
    {
        $category = Category::factory()->create(['name' => '食費']);

        $csv = "利用日,利用店名,利用金額\n2026/08/01,スーパーA,3200\n2026/08/02,取り込まない店,1000\n";
        $this->upload($csv);

        $this->confirmAll([0], [0 => ['category_id' => $category->id]])
            ->assertRedirect(route('transactions.index'));

        $this->assertSame(1, Transaction::count());
        $this->assertDatabaseHas('transactions', ['shop_name' => 'スーパーA', 'amount' => 3200, 'category_id' => $category->id]);
        $this->assertDatabaseMissing('transactions', ['shop_name' => '取り込まない店']);
    }

    public function test_1行も選択されていない場合はエラーになる(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/08/02,店,1000\n");

        $this->confirmAll([])->assertSessionHasErrors('rows');

        $this->assertSame(0, Transaction::count());
    }

    public function test_同じプレビューから二度送っても二重登録しない(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/08/01,スーパーA,3200\n");

        $rows = session('csv_import_rows');
        $token = session('csv_import_token');
        $payload = [
            'token' => $token,
            'rows' => [[
                'import' => '1',
                'transaction_date' => $rows[0]['transaction_date'],
                'type' => $rows[0]['type'],
                'category_id' => null,
                'shop_name' => $rows[0]['shop_name'],
                'amount' => $rows[0]['amount'],
            ]],
        ];

        $this->post(route('imports.confirm'), $payload)->assertRedirect(route('transactions.index'));
        $this->assertSame(1, Transaction::count());

        // ブラウザバックからの再送信
        $this->post(route('imports.confirm'), $payload)->assertRedirect(route('imports.create'));
        $this->assertSame(1, Transaction::count());
    }

    public function test_送信された行数が足りなければ登録しない(): void
    {
        // max_input_vars で後半が切り捨てられた状況
        $this->upload("利用日,利用店名,利用金額\n2026/08/01,店A,100\n2026/08/02,店B,200\n2026/08/03,店C,300\n");

        $rows = session('csv_import_rows');

        $this->from(route('imports.preview'))->post(route('imports.confirm'), [
            'token' => session('csv_import_token'),
            'rows' => [[
                'import' => '1',
                'transaction_date' => $rows[0]['transaction_date'],
                'type' => 'expense',
                'category_id' => null,
                'shop_name' => $rows[0]['shop_name'],
                'amount' => $rows[0]['amount'],
            ]],
        ])->assertSessionHasErrors('rows');

        $this->assertSame(0, Transaction::count());
    }

    public function test_確定の直前に登録された分は二重計上しない(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/08/01,スーパーA,3200\n2026/08/02,店B,1000\n");

        // プレビューを開いている間にレシートから同じ買い物を登録した
        Transaction::factory()->create([
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'amount' => 3200,
            'shop_name' => 'スーパーA',
        ]);

        $this->confirmAll([0, 1])->assertRedirect(route('transactions.index'));

        $this->assertSame(2, Transaction::count());
        $this->assertSame(1, Transaction::where('shop_name', 'スーパーA')->count());
        $this->assertStringContainsString('見送りました', (string) session('status'));
    }

    public function test_データが無い状態でプレビューを開くと取込画面に戻される(): void
    {
        $this->get(route('imports.preview'))->assertRedirect(route('imports.create'));
    }

    public function test_セッションが無い状態で確定を送っても登録しない(): void
    {
        $this->post(route('imports.confirm'), [
            'token' => 'dummy',
            'rows' => [['import' => '1', 'transaction_date' => '2026-08-01', 'type' => 'expense', 'amount' => 100]],
        ])->assertRedirect(route('imports.create'));

        $this->assertSame(0, Transaction::count());
    }

    /**
     * @return array<string, array{string, ?int}>
     */
    public static function 金額の書き方(): array
    {
        return [
            'カンマ区切り' => ['1,234', 1234],
            '円マーク' => ['¥1,234', 1234],
            '全角数字' => ['１２３４', 1234],
            '小数' => ['1234.00', 1234],
            '小数の端数' => ['1,234.56', 1235],
            'マイナス' => ['-1234', -1234],
            '三角' => ['△1,234', -1234],
            '黒三角' => ['▲1,234', -1234],
            '括弧' => ['(1,234)', -1234],
            '読めない' => ['1,234 (内消費税112円)', null],
            '日付が入っている' => ['2026/01/05', null],
            '空' => ['', null],
        ];
    }

    #[DataProvider('金額の書き方')]
    public function test_金額の書き方を取り違えない(string $input, ?int $expected): void
    {
        $this->assertSame($expected, app(CsvImportService::class)->parseAmount($input));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function 日付の書き方(): array
    {
        return [
            'スラッシュ' => ['2026/01/05', '2026-01-05'],
            'ハイフン' => ['2026-01-05', '2026-01-05'],
            'ドット' => ['2026.01.05', '2026-01-05'],
            '和風' => ['2026年1月5日', '2026-01-05'],
            '詰め' => ['20260105', '2026-01-05'],
            '時刻つき' => ['2026/01/05 12:34', '2026-01-05'],
            '曜日つき' => ['2026/01/05(月)', '2026-01-05'],
            '2桁年' => ['26/01/05', '2026-01-05'],
            '令和' => ['令和8年1月5日', '2026-01-05'],
            '令和略記' => ['R8.1.5', '2026-01-05'],
            '平成' => ['H31/4/30', '2019-04-30'],
            '存在しない日' => ['2026/02/30', null],
            '読めない' => ['未確定', null],
        ];
    }

    #[DataProvider('日付の書き方')]
    public function test_日付の書き方を取り違えない(string $input, ?string $expected): void
    {
        $this->assertSame($expected, app(CsvImportService::class)->parseDate($input));
    }

    public function test_カンマだらけの前置き行があってもタブ区切りと分かる(): void
    {
        $csv = "○○銀行 入出金明細\n出力日,2026/08/20\n口座番号,1234567,普通\n店番,001,支店名,中野\n"
            ."日付\t摘要\tお支払金額\n2026/07/01\tｽｰﾊﾟｰ\t3200\n";

        $this->upload($csv, 'bank.txt');

        $rows = session('csv_import_rows');
        $this->assertCount(1, $rows);
        $this->assertSame(3200, $rows[0]['amount']);
    }

    public function test_前置きに合計金額があっても本物のヘッダーを選ぶ(): void
    {
        $csv = "合計金額,52340\n\nご利用日,ご利用先,お支払額\n2026/07/01,スーパー,3200\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['importable']);
        $this->assertSame('2026-07-01', $rows[0]['transaction_date']);
        $this->assertSame(3200, $rows[0]['amount']);
    }

    public function test_新しい順のファイルでも年をまたいだ日付を取り違えない(): void
    {
        // 「最後の年つきの行」を使うと 1/5 が 2025年になってしまう
        $csv = "利用日,利用店名,利用金額\n2026/01/10,店A,1000\n1/5,店B,2000\n2025/12/28,店C,3000\n";

        $this->upload($csv);

        $this->assertSame('2026-01-05', session('csv_import_rows')[1]['transaction_date']);
    }

    public function test_日付や電話番号を金額として読まない(): void
    {
        $csv = "利用日,利用店名,利用金額\n2026/07/01,店A,2026-08-01\n2026/07/02,店B,03-1234-5678\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');

        // 符号を全部消す実装だと -20260801 / -312345678 という「収入」になっていた
        $this->assertFalse($rows[0]['importable']);
        $this->assertFalse($rows[1]['importable']);
    }

    public function test_残高の列を金額として選ばない(): void
    {
        $csv = "2026/07/01,ｽｰﾊﾟｰ,3200,415000\n2026/07/02,ｺﾝﾋﾞﾆ,540,414460\n";

        $this->upload($csv);

        $this->assertSame(3200, session('csv_import_rows')[0]['amount']);
    }

    public function test_入金列だけの銀行CSVを収入として読む(): void
    {
        $csv = "日付,摘要,入金金額\n2026/07/25,給与,300000\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertSame('income', $rows[0]['type']);
        $this->assertSame(300000, $rows[0]['amount']);
    }

    public function test_同じ内容の行が2つあっても両方登録できる(): void
    {
        // 同じ日に同じコンビニでコーヒーを2回、は普通にある
        $csv = "利用日,利用店名,利用金額\n2026/07/01,セブンイレブン,150\n2026/07/01,セブンイレブン,150\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');
        $this->assertNull($rows[0]['repeat_of_line']);
        $this->assertSame(1, $rows[1]['repeat_of_line']);
        $this->assertTrue($rows[1]['importable']);

        $this->confirmAll([0, 1])->assertRedirect(route('transactions.index'));

        $this->assertSame(2, Transaction::count());
        $this->assertStringNotContainsString('見送りました', (string) session('status'));
    }

    public function test_行数が合わなければバリデーションより先に止める(): void
    {
        // 切り捨ては行の途中で起きるので、先にバリデーションを通すと
        // 「rows.166.amount には値が必要です」という原因の分からないエラーになる
        $this->upload("利用日,利用店名,利用金額\n2026/07/01,店A,100\n2026/07/02,店B,200\n");

        $response = $this->from(route('imports.preview'))->post(route('imports.confirm'), [
            'token' => session('csv_import_token'),
            'rows' => [
                ['import' => '1', 'transaction_date' => '2026-07-01', 'type' => 'expense', 'category_id' => null, 'shop_name' => '店A'],
            ],
        ]);

        $response->assertSessionHasErrors('rows');
        $this->assertStringContainsString(
            'max_input_vars',
            (string) session('errors')->first('rows'),
        );
        $this->assertSame(0, Transaction::count());
    }

    public function test_選択なしで戻したあとにやり直せる(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/07/01,店A,100\n");

        // 1回目：チェックを付け忘れた
        $this->confirmAll([])->assertSessionHasErrors('rows');

        // 2回目：付け直したら通る（トークンを先に使い切っていると通らない）
        $this->confirmAll([0])->assertRedirect(route('transactions.index'));

        $this->assertSame(1, Transaction::count());
    }

    public function test_すべて重複なら二重登録していないと伝える(): void
    {
        Transaction::factory()->create([
            'transaction_date' => '2026-07-01',
            'type' => 'expense',
            'amount' => 3200,
            'shop_name' => 'スーパーA',
        ]);

        $this->upload("利用日,利用店名,利用金額\n2026/07/01,スーパーA,3200\n");

        $this->confirmAll([0])->assertRedirect(route('transactions.index'));

        $this->assertSame(1, Transaction::count());
        $this->assertStringContainsString('二重登録はしていません', (string) session('status'));
    }

    /**
     * レシートから登録した取引を作る（店名の書き方はレシート側のもの）。
     */
    private function receiptTransaction(string $date, int $amount, string $shop): Transaction
    {
        $receipt = \App\Models\ReceiptImage::create([
            'path' => 'receipts/dummy.jpg',
            'status' => 'processed',
        ]);

        return Transaction::factory()->create([
            'transaction_date' => $date,
            'type' => 'expense',
            'amount' => $amount,
            'shop_name' => $shop,
            'receipt_image_id' => $receipt->id,
        ]);
    }

    public function test_レシートで登録済みの支払いを候補として知らせる(): void
    {
        // 店名の書き方が全く違うので、日付＋店名＋金額の重複判定には引っかからない
        $this->receiptTransaction('2026-08-01', 3200, 'スーパーライフ 中野店');

        $csv = "利用日,利用店名,利用金額\n2026/08/03,ﾗｲﾌ ﾅｶﾉ,3200\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');

        $this->assertFalse($rows[0]['duplicate']);
        $this->assertNotNull($rows[0]['receipt_match_of']);
        $this->assertStringContainsString('スーパーライフ 中野店', $rows[0]['receipt_match_of']);

        // 既定でチェックを外す（二重計上のほうが取り返しがつかない）
        $this->get(route('imports.preview'))
            ->assertOk()
            ->assertSee('レシートと同じ支払いかも');
    }

    public function test_日付が離れすぎていればレシート候補にしない(): void
    {
        $this->receiptTransaction('2026-08-01', 3200, 'スーパーライフ 中野店');

        // 4日離れている
        $this->upload("利用日,利用店名,利用金額\n2026/08/05,ﾗｲﾌ ﾅｶﾉ,3200\n");

        $this->assertNull(session('csv_import_rows')[0]['receipt_match_of']);
    }

    public function test_金額が違えばレシート候補にしない(): void
    {
        $this->receiptTransaction('2026-08-01', 3200, 'スーパーライフ 中野店');

        $this->upload("利用日,利用店名,利用金額\n2026/08/01,ﾗｲﾌ ﾅｶﾉ,3201\n");

        $this->assertNull(session('csv_import_rows')[0]['receipt_match_of']);
    }

    public function test_手入力の取引はレシート候補にしない(): void
    {
        // receipt_image_id が無い取引は「レシートで登録済み」ではない
        Transaction::factory()->create([
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'amount' => 3200,
            'shop_name' => '手入力の店',
            'receipt_image_id' => null,
        ]);

        $this->upload("利用日,利用店名,利用金額\n2026/08/02,ﾗｲﾌ ﾅｶﾉ,3200\n");

        $this->assertNull(session('csv_import_rows')[0]['receipt_match_of']);
    }

    public function test_1枚のレシートを複数行の候補にしない(): void
    {
        $this->receiptTransaction('2026-08-01', 3200, 'スーパーライフ 中野店');

        $csv = "利用日,利用店名,利用金額\n2026/08/01,ﾗｲﾌ ﾅｶﾉ,3200\n2026/08/02,ﾗｲﾌ ﾅｶﾉ,3200\n";

        $this->upload($csv);

        $rows = session('csv_import_rows');

        // 日付の近いほうに割り当てる
        $this->assertNotNull($rows[0]['receipt_match_of']);
        $this->assertNull($rows[1]['receipt_match_of']);
    }

    public function test_レシート候補でも自分でチェックすれば登録できる(): void
    {
        $this->receiptTransaction('2026-08-01', 3200, 'スーパーライフ 中野店');

        $this->upload("利用日,利用店名,利用金額\n2026/08/02,ﾗｲﾌ ﾅｶﾉ,3200\n");

        $this->confirmAll([0])->assertRedirect(route('transactions.index'));

        // 「候補」であって禁止ではない。人が同じではないと判断したら入る
        $this->assertSame(2, Transaction::count());
    }

    public function test_列の対応を手で指定して読み直せる(): void
    {
        // 店名の列と金額の列が入れ替わって判定されても、画面から直せる
        $this->upload("列A,列B,列C\n2026/07/01,3200,スーパー\n");

        $this->post(route('imports.remap'), [
            'mapping' => ['header_row' => 0, 'date' => 0, 'shop' => 2, 'amount' => 1],
        ])->assertRedirect(route('imports.preview'));

        $rows = session('csv_import_rows');

        $this->assertCount(1, $rows);
        $this->assertSame('2026-07-01', $rows[0]['transaction_date']);
        $this->assertSame('スーパー', $rows[0]['shop_name']);
        $this->assertSame(3200, $rows[0]['amount']);
        $this->assertTrue($rows[0]['importable']);
    }

    public function test_日付か金額の指定が無ければ読み直さない(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/07/01,スーパー,3200\n");

        $this->from(route('imports.preview'))->post(route('imports.remap'), [
            'mapping' => ['header_row' => 0, 'date' => null, 'shop' => 1, 'amount' => null],
        ])->assertSessionHasErrors('mapping');
    }

    public function test_元のCSVが無ければ取込画面に戻す(): void
    {
        $this->post(route('imports.remap'), [
            'mapping' => ['header_row' => 0, 'date' => 0, 'amount' => 2],
        ])->assertRedirect(route('imports.create'));
    }

    public function test_同じ並びのCSVには前回指定した列を使う(): void
    {
        // 1回目：手で直して登録する
        $this->upload("列A,列B,列C\n2026/07/01,3200,スーパー\n");
        $this->post(route('imports.remap'), [
            'mapping' => ['header_row' => 0, 'date' => 0, 'shop' => 2, 'amount' => 1],
        ]);
        $this->confirmAll([0])->assertRedirect(route('transactions.index'));

        $this->assertDatabaseHas('import_profiles', ['label' => 'meisai.csv']);

        // 2回目：同じ並びのCSVなら自動で同じ対応になる
        $this->upload("列A,列B,列C\n2026/08/01,4500,コンビニ\n");

        $rows = session('csv_import_rows');

        $this->assertSame('コンビニ', $rows[0]['shop_name']);
        $this->assertSame(4500, $rows[0]['amount']);
    }

    public function test_自動判定で通った取込は列の対応を覚えない(): void
    {
        // 自動判定のぶんまで覚えると、まったく別のCSVに前回の対応を当ててしまう
        $this->upload("利用日,利用店名,利用金額\n2026/07/01,スーパー,3200\n");
        $this->confirmAll([0])->assertRedirect(route('transactions.index'));

        $this->assertSame(0, \App\Models\ImportProfile::count());
    }

    public function test_ヘッダー行の候補には行が並ぶ(): void
    {
        $this->upload("○○カードご利用代金明細\nお客様番号,1234567890\n利用日,利用店名,利用金額\n2026/07/01,スーパー,3200\n");

        // 列の一覧を使い回すと、3列しかないので3行目までしか選べない
        $this->get(route('imports.preview'))
            ->assertOk()
            ->assertSee('1行目: ○○カードご利用代金明細')
            ->assertSee('3行目: 利用日, 利用店名, 利用金額');
    }

    public function test_ヘッダー行を最終行にしても行き止まりにならない(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/07/01,スーパー,3200\n");

        // データ行が0件になる指定。プレビューが空になって
        // 「セッションが切れた」ように見えるのを防ぐ
        $this->from(route('imports.preview'))->post(route('imports.remap'), [
            'mapping' => ['header_row' => 1, 'date' => 0, 'shop' => 1, 'amount' => 2],
        ])->assertSessionHasErrors('mapping');

        // 元の状態は残っている
        $this->assertCount(1, session('csv_import_rows'));
    }

    public function test_前置き行があるCSVでも印は毎月同じになる(): void
    {
        $this->upload("ご利用代金明細,2026年7月分\n利用日,利用店名,利用金額\n2026/07/01,スーパー,3200\n");
        $this->post(route('imports.remap'), [
            'mapping' => ['header_row' => 1, 'date' => 0, 'shop' => 1, 'amount' => 2],
        ]);
        $this->confirmAll([0]);

        $this->assertSame(1, \App\Models\ImportProfile::count());

        // 月が変わって前置き行の文言が変わっても、同じ設定として扱う
        $this->upload("ご利用代金明細,2026年8月分\n利用日,利用店名,利用金額\n2026/08/01,コンビニ,540\n");
        $this->confirmAll([0]);

        $this->assertSame(1, \App\Models\ImportProfile::count());
    }

    public function test_見出しの無いCSVは列の対応を覚えない(): void
    {
        // 列数だけで「同じカード会社」と決めつけると、別のCSVに前回の対応を当ててしまう
        $this->upload("2026/07/01,スーパー,3200\n");
        $this->post(route('imports.remap'), [
            'mapping' => ['header_row' => null, 'date' => 0, 'shop' => 1, 'amount' => 2],
        ]);
        $this->confirmAll([0]);

        $this->assertSame(0, \App\Models\ImportProfile::count());
    }

    public function test_2回目のアップロードが壊れていても1回目は使えるまま(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/07/01,スーパー,3200\n");

        $this->from(route('imports.create'))
            ->post(route('imports.store'), ['file' => $this->csv('')])
            ->assertSessionHasErrors('file');

        // 1回目のCSVが消えていなければ、列の指定はまだやり直せる
        $this->post(route('imports.remap'), [
            'mapping' => ['header_row' => 0, 'date' => 0, 'shop' => 1, 'amount' => 2],
        ])->assertRedirect(route('imports.preview'));
    }

    public function test_列の候補にヘッダー名が出る(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/07/01,スーパー,3200\n");

        $this->get(route('imports.preview'))
            ->assertOk()
            ->assertSee('1列目: 利用日')
            ->assertSee('3列目: 利用金額');
    }

    public function test_今後取り込まない店を覚えて次回は外す(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/07/01,カード引き落とし,50000\n2026/07/02,スーパー,3200\n");

        // 引き落とし行は取り込まず、今後も外すよう覚えさせる
        $rows = session('csv_import_rows');

        $this->post(route('imports.confirm'), [
            'token' => session('csv_import_token'),
            'rows' => [
                0 => [
                    'ignore' => '1',
                    'transaction_date' => $rows[0]['transaction_date'],
                    'type' => $rows[0]['type'],
                    'category_id' => null,
                    'shop_name' => $rows[0]['shop_name'],
                    'amount' => $rows[0]['amount'],
                ],
                1 => [
                    'import' => '1',
                    'transaction_date' => $rows[1]['transaction_date'],
                    'type' => $rows[1]['type'],
                    'category_id' => null,
                    'shop_name' => $rows[1]['shop_name'],
                    'amount' => $rows[1]['amount'],
                ],
            ],
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame(1, Transaction::count());
        $this->assertDatabaseHas('import_ignore_rules', ['display_name' => 'カード引き落とし']);

        // 次回は既定でチェックが外れる（行自体は残す）
        $this->upload("利用日,利用店名,利用金額\n2026/08/01,カード引き落とし,50000\n2026/08/02,スーパー,3200\n");

        $next = session('csv_import_rows');

        $this->assertNotNull($next[0]['ignored_by']);
        $this->assertTrue($next[0]['importable']);
        $this->assertNull($next[1]['ignored_by']);
    }

    public function test_取り込まないルールは店名のゆれを吸収する(): void
    {
        \App\Models\ImportIgnoreRule::remember('カード引き落とし');

        $this->upload("利用日,利用店名,利用金額\nカード引き落とし,,0\n2026/07/01,ｶｰﾄﾞ引き落とし 7月分,50000\n");

        $rows = session('csv_import_rows');

        $this->assertNotNull($rows[1]['ignored_by']);
    }

    public function test_取り込まないルールを解除できる(): void
    {
        $rule = \App\Models\ImportIgnoreRule::remember('カード引き落とし');

        $this->get(route('imports.create'))->assertOk()->assertSee('カード引き落とし');

        $this->delete(route('imports.ignore-rules.destroy', $rule))
            ->assertRedirect(route('imports.create'));

        $this->assertSame(0, \App\Models\ImportIgnoreRule::count());
    }

    public function test_取り込まない登録だけでもエラーにしない(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/07/01,カード引き落とし,50000\n");

        $rows = session('csv_import_rows');

        // 1件も取り込まないが、ルールだけ覚える操作
        $this->from(route('imports.preview'))->post(route('imports.confirm'), [
            'token' => session('csv_import_token'),
            'rows' => [[
                'ignore' => '1',
                'transaction_date' => $rows[0]['transaction_date'],
                'type' => $rows[0]['type'],
                'category_id' => null,
                'shop_name' => $rows[0]['shop_name'],
                'amount' => $rows[0]['amount'],
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, \App\Models\ImportIgnoreRule::count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_取り込まない登録の直後にその行が取込可に戻らない(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/07/01,カード引き落とし,50000\n");

        $rows = session('csv_import_rows');

        $this->from(route('imports.preview'))->post(route('imports.confirm'), [
            'token' => session('csv_import_token'),
            'rows' => [[
                'ignore' => '1',
                'transaction_date' => $rows[0]['transaction_date'],
                'type' => $rows[0]['type'],
                'category_id' => null,
                'shop_name' => $rows[0]['shop_name'],
                'amount' => $rows[0]['amount'],
            ]],
        ])->assertRedirect(route('imports.preview'));

        // 反映しないと、いま「取り込まない」にした行がチェック付きで再表示され、
        // そのまま登録できてしまう
        $this->assertNotNull(session('csv_import_rows')[0]['ignored_by']);
    }

    public function test_空白だけの店名は取り込まないルールにしない(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/07/01,カード引き落とし,50000\n");

        $rows = session('csv_import_rows');

        $this->from(route('imports.preview'))->post(route('imports.confirm'), [
            'token' => session('csv_import_token'),
            'rows' => [[
                'ignore' => '1',
                'transaction_date' => $rows[0]['transaction_date'],
                'type' => $rows[0]['type'],
                'category_id' => null,
                'shop_name' => '   ',
                'amount' => $rows[0]['amount'],
            ]],
        ])->assertSessionHasErrors('rows');

        // 何も覚えていないのに「登録しました」と出してはいけない
        $this->assertSame(0, \App\Models\ImportIgnoreRule::count());
    }

    public function test_同じ店を2行ぶんチェックしても1件として数える(): void
    {
        $this->upload("利用日,利用店名,利用金額\n2026/07/01,ｱﾏｿﾞﾝ,1000\n2026/07/02,ｱﾏｿﾞﾝ,2000\n");

        $rows = session('csv_import_rows');
        $payload = ['token' => session('csv_import_token'), 'rows' => []];

        foreach ($rows as $i => $row) {
            $payload['rows'][$i] = [
                'ignore' => '1',
                'transaction_date' => $row['transaction_date'],
                'type' => $row['type'],
                'category_id' => null,
                'shop_name' => $row['shop_name'],
                'amount' => $row['amount'],
            ];
        }

        $this->from(route('imports.preview'))->post(route('imports.confirm'), $payload);

        $this->assertSame(1, \App\Models\ImportIgnoreRule::count());
        $this->assertStringContainsString('1件の店', (string) session('status'));
    }
}
