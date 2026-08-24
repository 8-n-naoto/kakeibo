<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\MerchantCategoryGuesser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * まとめて扱う画面で、行数なりにクエリが増えていないか。
 *
 * 家計簿は年数がそのまま価値になるので、数年分たまったときに
 * 「一括編集を押したらタイムアウトする」になるのが困る。
 * 件数を変えても定数で収まることを、上限つきで見張る。
 */
class BulkQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private Category $food;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->food = Category::factory()->create(['name' => '食費', 'type' => 'expense']);
        Category::factory()->create(['name' => '日用品', 'type' => 'expense']);
    }

    /**
     * @param  callable():void  $action
     * @return list<string>
     */
    private function queriesDuring(callable $action): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $action();

        // リスナーはこのあとも登録されたままだが、返すのはコピーなので
        // 測定後のクエリが混ざることはない（1テストにつき1回だけ呼ぶこと）
        return $queries;
    }

    public function test_一括編集のクエリ数は件数に比例しない(): void
    {
        $other = Category::factory()->create(['name' => 'その他', 'type' => 'expense']);

        Transaction::factory()->count(60)->create([
            'type' => 'expense',
            'category_id' => $this->food->id,
            'transaction_date' => '2026-08-01',
            'amount' => 1000,
        ]);

        $queries = $this->queriesDuring(function () use ($other): void {
            $this->post(route('transactions.bulk-update'), [
                'scope' => 'filtered',
                'filtered_count' => 60,
                'bulk_category_id' => $other->id,
            ])->assertRedirect(route('transactions.index'));
        });

        $this->assertSame($other->id, Transaction::first()->category_id);

        // 件数（60）よりずっと少ないこと。IDを展開して whereIn に渡す実装だと
        // ここは通っても max_allowed_packet で落ちるので、UPDATE が1本かも見る
        $this->assertLessThan(20, count($queries), '一括編集のクエリ: '.implode(' / ', $queries));
        $this->assertSame(
            1,
            count(array_filter($queries, fn (string $sql) => str_starts_with($sql, 'update "transactions"'))),
            'UPDATE は1本であるべき',
        );
    }

    public function test_CSV確定でカテゴリを行数ぶん引かない(): void
    {
        // `exists:categories,id` のままだと、行ごとに SELECT が飛ぶ
        $csv = "日付,店名,金額\n";

        for ($i = 1; $i <= 20; $i++) {
            $csv .= sprintf("2026/08/%02d,ライフ %d号店,%d\n", $i, $i, 100 * $i);
        }

        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $csv);

        $this->post(route('imports.store'), [
            'file' => new UploadedFile($path, 'meisai.csv', 'text/csv', null, true),
        ]);

        $rows = [];

        foreach (session('csv_import_rows') as $i => $row) {
            $rows[$i] = [
                'import' => '1',
                'transaction_date' => $row['transaction_date'],
                'type' => $row['type'],
                'category_id' => $this->food->id,
                'shop_name' => $row['shop_name'],
                'amount' => $row['amount'],
            ];
        }

        $queries = $this->queriesDuring(function () use ($rows): void {
            $this->post(route('imports.confirm'), [
                'token' => session('csv_import_token'),
                'rows' => $rows,
            ]);
        });

        // カテゴリ表そのものを引くクエリだけ数える
        // （学習ルールの whereHas は categories を副問い合わせに含むので除く）
        $categorySelects = array_filter(
            $queries,
            fn (string $sql) => str_starts_with($sql, 'select')
                && str_contains($sql, 'from "categories"')
                && ! str_contains($sql, 'merchant_category_rules'),
        );

        $this->assertSame(20, Transaction::count());
        $this->assertLessThan(
            8,
            count($categorySelects),
            'カテゴリのSELECTが行数（20）ぶん飛んでいる: '.count($categorySelects).'回',
        );
    }

    public function test_学習しながらでもルール全件を毎回読み直さない(): void
    {
        // remember() のたびに一覧を捨てると、1取引につきルール全件の再SELECTになる
        $guesser = app(MerchantCategoryGuesser::class);

        // 1回読み込ませる
        $guesser->guessByRule('はじめの店');

        $queries = $this->queriesDuring(function () use ($guesser): void {
            for ($i = 1; $i <= 20; $i++) {
                $guesser->remember('お店 '.$i, $this->food->id, 'manual');
                $guesser->guessByRule('お店 '.$i);
            }
        });

        // 狙いは rules() の全件読み込み。firstOrNew() の1件引きは対象外
        $ruleSelects = array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'from "merchant_category_rules" where exists'),
        );

        // 覚えた分がちゃんと引けること
        $this->assertSame($this->food->id, $guesser->guessByRule('お店 7'));
        $this->assertLessThan(
            10,
            count($ruleSelects),
            'ルール全件のSELECTが繰り返されている: '.count($ruleSelects).'回',
        );
    }
}
