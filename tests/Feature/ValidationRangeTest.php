<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MerchantCategoryRule;
use App\Models\Transaction;
use App\Services\CsvImportService;
use App\Support\DomainLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 「ありえる値」の範囲（App\Support\DomainLimits）。
 *
 * ここが抜けると、静かに壊れる。
 *  - 2000年より前の日付は、取引一覧とCSVエクスポートには出るのに、
 *    年間まとめ・ダッシュボード・予算のどれにも出ない。「合計が合わない」だけが残る。
 *  - 金額に上限が無いと、桁化けした1行でCSV取込の合計が溢れ、取込が丸ごと落ち続ける。
 *  - 支出の行に収入カテゴリが付くと、円グラフには出るのに固定／変動の内訳からは漏れる。
 */
class ValidationRangeTest extends TestCase
{
    use RefreshDatabase;

    private Category $food;

    private Category $salary;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 10, 0, 0));

        $this->food = Category::factory()->create(['name' => '食費', 'type' => 'expense']);
        $this->salary = Category::factory()->create(['name' => '給与', 'type' => 'income']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function transactionPayload(array $overrides = []): array
    {
        return array_merge([
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'category_id' => $this->food->id,
            'shop_name' => 'イオン',
            'memo' => null,
            'amount' => 1200,
        ], $overrides);
    }

    // ---- 日付 ----

    public function test_2000年より前の日付の取引は登録できない(): void
    {
        // カード会社CSVの元号誤読で 1899 になる明細が実在する
        $response = $this->from(route('transactions.create'))->post(
            route('transactions.store'),
            $this->transactionPayload(['transaction_date' => '1899-01-05']),
        );

        $response->assertSessionHasErrors('transaction_date');
        $this->assertSame(0, Transaction::count());
    }

    public function test_下限ちょうどの日付は登録できる(): void
    {
        $this->post(route('transactions.store'), $this->transactionPayload([
            'transaction_date' => DomainLimits::minDate(),
        ]))->assertRedirect(route('transactions.index'));

        $this->assertSame(1, Transaction::count());
    }

    public function test_ずっと先の日付の取引は登録できない(): void
    {
        $response = $this->from(route('transactions.create'))->post(
            route('transactions.store'),
            $this->transactionPayload(['transaction_date' => '2099-01-01']),
        );

        $response->assertSessionHasErrors('transaction_date');
        $this->assertSame(0, Transaction::count());
    }

    public function test_来年の年末までは登録できる(): void
    {
        // クレジットの翌月確定ぶんや定期支出の予定を入れられる程度の余裕は残す
        $this->post(route('transactions.store'), $this->transactionPayload([
            'transaction_date' => '2027-12-31',
        ]))->assertRedirect(route('transactions.index'));

        $this->assertSame(1, Transaction::count());
    }

    // ---- 金額 ----

    public function test_上限を超える金額の取引は登録できない(): void
    {
        $response = $this->from(route('transactions.create'))->post(
            route('transactions.store'),
            $this->transactionPayload(['amount' => DomainLimits::MAX_AMOUNT + 1]),
        );

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, Transaction::count());
    }

    public function test_上限ちょうどの金額は登録できる(): void
    {
        $this->post(route('transactions.store'), $this->transactionPayload([
            'amount' => DomainLimits::MAX_AMOUNT,
        ]))->assertRedirect(route('transactions.index'));

        $this->assertSame(DomainLimits::MAX_AMOUNT, Transaction::first()->amount);
    }

    public function test_資産スナップショットの金額にも上限がある(): void
    {
        $response = $this->from(route('assets.create'))->post(route('assets.store'), [
            'snapshot_date' => '2026-08-01',
            'cash_amount' => DomainLimits::MAX_AMOUNT + 1,
            'nisa_amount' => 0,
            'ideco_amount' => 0,
            'other_investment_amount' => 0,
        ]);

        $response->assertSessionHasErrors('cash_amount');
    }

    // ---- カテゴリの種別スコープ ----

    public function test_支出の取引に収入カテゴリは付けられない(): void
    {
        $response = $this->from(route('transactions.create'))->post(
            route('transactions.store'),
            $this->transactionPayload(['type' => 'expense', 'category_id' => $this->salary->id]),
        );

        $response->assertSessionHasErrors('category_id');
        $this->assertSame(0, Transaction::count());
    }

    public function test_収入の取引には収入カテゴリを付けられる(): void
    {
        $this->post(route('transactions.store'), $this->transactionPayload([
            'type' => 'income',
            'category_id' => $this->salary->id,
            'amount' => 300000,
        ]))->assertRedirect(route('transactions.index'));

        $this->assertSame($this->salary->id, Transaction::first()->category_id);
    }

    public function test_取引の編集でも種別とカテゴリの組み合わせを見る(): void
    {
        $transaction = Transaction::factory()->create([
            'type' => 'expense',
            'category_id' => $this->food->id,
            'transaction_date' => '2026-08-01',
            'amount' => 1000,
        ]);

        $response = $this->from(route('transactions.edit', $transaction))->put(
            route('transactions.update', $transaction),
            $this->transactionPayload(['type' => 'expense', 'category_id' => $this->salary->id]),
        );

        $response->assertSessionHasErrors('category_id');
        $this->assertSame($this->food->id, $transaction->fresh()->category_id);
    }

    public function test_定期支出も種別でカテゴリを絞る(): void
    {
        $response = $this->from(route('recurring.create'))->post(route('recurring.store'), [
            'name' => '家賃',
            'type' => 'expense',
            'category_id' => $this->salary->id,
            'amount' => 80000,
            'day_of_month' => 27,
        ]);

        $response->assertSessionHasErrors('category_id');
    }

    public function test_自動分類ルールに収入カテゴリは登録できない(): void
    {
        // 学習ルールは支出の推測にしか使わない。ここが収入カテゴリを指すと、
        // 以後のCSV取込で支出行に収入カテゴリが数百件まとめて付く
        $response = $this->from(route('merchant-rules.index'))->post(route('merchant-rules.store'), [
            'shop_name' => 'ライフ',
            'category_id' => $this->salary->id,
        ]);

        $response->assertSessionHasErrors('category_id');
        $this->assertSame(0, MerchantCategoryRule::count());
    }

    public function test_自動分類ルールの更新でも収入カテゴリを弾く(): void
    {
        app(\App\Services\MerchantCategoryGuesser::class)
            ->remember('ライフ', $this->food->id, MerchantCategoryRule::SOURCE_MANUAL);

        $rule = MerchantCategoryRule::firstOrFail();

        $response = $this->from(route('merchant-rules.index'))->put(
            route('merchant-rules.update', $rule),
            ['category_id' => $this->salary->id],
        );

        $response->assertSessionHasErrors('category_id');
        $this->assertSame($this->food->id, $rule->fresh()->category_id);
    }

    public function test_使われているカテゴリの種別は変えられない(): void
    {
        // 種別を変えると、そのカテゴリを指す取引・定期支出・学習ルール・予算が
        // まとめて「種別違い」になる。画面には何も出ないまま、次の保存で弾かれる
        Transaction::factory()->create([
            'type' => 'expense',
            'category_id' => $this->food->id,
            'transaction_date' => '2026-08-01',
            'amount' => 1000,
        ]);

        $response = $this->from(route('categories.edit', $this->food))->put(
            route('categories.update', $this->food),
            [
                'name' => '食費',
                'type' => 'income',
                'color' => '#123456',
                'sort_order' => 1,
            ],
        );

        $response->assertSessionHasErrors('type');
        $this->assertSame('expense', $this->food->fresh()->type);
    }

    public function test_使われていないカテゴリの種別は変えられる(): void
    {
        $unused = Category::factory()->create(['name' => '雑費', 'type' => 'expense']);

        $this->put(route('categories.update', $unused), [
            'name' => '雑費',
            'type' => 'income',
            'color' => '#123456',
            'sort_order' => 1,
        ])->assertRedirect(route('categories.index'));

        $this->assertSame('income', $unused->fresh()->type);
    }

    public function test_収入カテゴリを指す学習ルールは推測に使わない(): void
    {
        // 種別は後から変えられるので、収入を指す古いルールが残りうる。
        // それを使うと、支出行に収入カテゴリが付いたままプレビューが緑になり、
        // 確定で全行が弾かれる
        $guesser = app(\App\Services\MerchantCategoryGuesser::class);
        $guesser->remember('ライフ', $this->salary->id, MerchantCategoryRule::SOURCE_MANUAL);

        $this->assertNull(app(\App\Services\MerchantCategoryGuesser::class)->guessByRule('ライフ'));
    }

    public function test_種別の合わない定期支出は未分類で計上する(): void
    {
        // 毎月「集計が合わない取引」を作り続けないようにする。
        // 計上そのものは止めず、未分類にして人の目に留まるようにする
        $rule = \App\Models\RecurringTransaction::create([
            'name' => '謎の引き落とし',
            'type' => 'expense',
            'category_id' => $this->salary->id,
            'amount' => 1000,
            'day_of_month' => 1,
            'is_active' => true,
        ]);

        $result = app(\App\Services\RecurringTransactionService::class)
            ->post(Carbon::create(2026, 8, 1));

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['mismatched']);

        $transaction = Transaction::where('recurring_transaction_id', $rule->id)->firstOrFail();

        $this->assertNull($transaction->category_id);
    }

    // ---- CSV取込 ----

    private function upload(string $contents)
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $contents);

        return $this->post(route('imports.store'), [
            'file' => new UploadedFile($path, 'meisai.csv', 'text/csv', null, true),
        ]);
    }

    public function test_CSVの1899年の行は取込不可になる(): void
    {
        // 「取込可」で緑表示されると、集計に出てこない取引が静かに増える
        $this->upload("日付,店名,金額\n1899/01/05,ライフ,1200\n2026/08/01,ライフ,900\n");

        $rows = session('csv_import_rows');

        $this->assertFalse($rows[0]['importable']);
        $this->assertStringContainsString('日付', (string) $rows[0]['error']);
        $this->assertTrue($rows[1]['importable']);
    }

    public function test_CSVの桁化けした金額は取込不可になる(): void
    {
        $this->upload("日付,店名,金額\n2026/08/01,ライフ,9223372036854775807\n2026/08/02,ライフ,1200\n");

        $rows = session('csv_import_rows');

        $this->assertFalse($rows[0]['importable']);
        $this->assertStringContainsString('金額', (string) $rows[0]['error']);
        $this->assertTrue($rows[1]['importable']);
    }

    public function test_parseAmountは上限を超える値をnullにする(): void
    {
        $service = app(CsvImportService::class);

        $this->assertSame(DomainLimits::MAX_AMOUNT, $service->parseAmount((string) DomainLimits::MAX_AMOUNT));
        $this->assertNull($service->parseAmount((string) (DomainLimits::MAX_AMOUNT + 1)));
        // 返金（マイナス）も同じ幅で見る
        $this->assertSame(-DomainLimits::MAX_AMOUNT, $service->parseAmount('-'.DomainLimits::MAX_AMOUNT));
        $this->assertNull($service->parseAmount('-'.(DomainLimits::MAX_AMOUNT + 1)));
    }

    public function test_CSVの返金行にはカテゴリを推測しない(): void
    {
        // 推測できるのは支出カテゴリだけ。返金（入金＝収入）の行に支出カテゴリを載せると
        // プレビューは緑なのに確定で種別違いとして弾かれる、という分かりにくい失敗になる
        Category::factory()->create(['name' => 'コンビニ', 'type' => 'expense']);

        // マイナス金額＝返金（収入扱い）
        $this->upload("日付,店名,金額\n2026/08/01,セブンイレブン,-500\n2026/08/02,セブンイレブン,780\n");

        $rows = session('csv_import_rows');

        $this->assertSame('income', $rows[0]['type']);
        $this->assertNull($rows[0]['category_id'], '返金行にカテゴリを推測している');

        $this->assertSame('expense', $rows[1]['type']);
    }

    public function test_種別違いのカテゴリでも編集画面では選ばれたまま出る(): void
    {
        // この機能を入れる前のデータには種別違いの行が普通にある。
        // 画面を開いただけで「未分類」に戻ると、次の保存で本当に消える
        $transaction = Transaction::factory()->create([
            'type' => 'income',
            'category_id' => $this->food->id,
            'transaction_date' => '2026-08-01',
            'amount' => 500,
        ]);

        $response = $this->get(route('transactions.edit', $transaction));

        $response->assertOk();
        $response->assertSee('value="'.$this->food->id.'" selected', false);
    }

    public function test_収入カテゴリを指す古いルールは選び直しを促す(): void
    {
        // 一覧の候補は支出だけになった。空欄が無いとブラウザが先頭を勝手に選び、
        // 保存を押した瞬間に身に覚えのないカテゴリへ付け替わる
        app(\App\Services\MerchantCategoryGuesser::class)
            ->remember('勤務先', $this->salary->id, MerchantCategoryRule::SOURCE_MANUAL);

        $response = $this->get(route('merchant-rules.index'));

        $response->assertOk();
        $response->assertSee('（支出カテゴリを選び直してください）');
    }

    public function test_CSV確定でも種別とカテゴリの組み合わせを見る(): void
    {
        $this->upload("日付,店名,金額\n2026/08/01,ライフ,1200\n");

        $response = $this->from(route('imports.create'))->post(route('imports.confirm'), [
            'token' => session('csv_import_token'),
            'rows' => [
                0 => [
                    'import' => '1',
                    'transaction_date' => '2026-08-01',
                    'type' => 'expense',
                    'category_id' => $this->salary->id,
                    'shop_name' => 'ライフ',
                    'amount' => 1200,
                ],
            ],
        ]);

        $response->assertSessionHasErrors('rows.0.category_id');
        $this->assertSame(0, Transaction::count());
    }
}
