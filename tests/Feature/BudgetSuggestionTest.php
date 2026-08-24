<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\BudgetSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 過去実績からの予算提案。
 *
 * 「一度きりの大きな買い物で予算が跳ね上がらないこと」と
 * 「実績が足りないときに提案しないこと」がこの機能の肝なので、そこを厚く見る。
 */
class BudgetSuggestionTest extends TestCase
{
    use RefreshDatabase;

    /** 提案の対象月。この月は実績に含めない */
    private Carbon $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = Carbon::create(2026, 8, 1)->startOfMonth();
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 10, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function service(): BudgetSuggestionService
    {
        return app(BudgetSuggestionService::class);
    }

    /**
     * @param  array<string, int>  $amountsByMonth  'Y-m' => 金額（0 なら計上しない）
     */
    private function spend(Category $category, array $amountsByMonth): void
    {
        foreach ($amountsByMonth as $month => $amount) {
            if ($amount === 0) {
                continue;
            }

            Transaction::factory()->create([
                'transaction_date' => $month.'-10',
                'type' => 'expense',
                'category_id' => $category->id,
                'amount' => $amount,
            ]);
        }
    }

    private function food(): Category
    {
        return Category::factory()->create(['name' => '食費', 'expense_nature' => Category::NATURE_VARIABLE]);
    }

    private function rent(): Category
    {
        return Category::factory()->create(['name' => '家賃', 'expense_nature' => Category::NATURE_FIXED]);
    }

    private function rowFor(array $suggestion, string $name): array
    {
        $row = $suggestion['categories']->firstWhere('name', $name);

        $this->assertNotNull($row, $name.' の提案がありません');

        return $row;
    }

    public function test_変動費は中央値をもとに提案する(): void
    {
        $food = $this->food();

        // 6ヶ月のうち1ヶ月だけ突出（帰省でまとめ買い）
        $this->spend($food, [
            '2026-02' => 40000,
            '2026-03' => 42000,
            '2026-04' => 38000,
            '2026-05' => 41000,
            '2026-06' => 39000,
            '2026-07' => 150000,
        ]);

        $row = $this->rowFor($this->service()->suggest($this->target), '食費');

        // 中央値は 40,500 → 41,000。平均（58,333）を使うと突出に引きずられる
        $this->assertSame(40500, $row['median']);
        $this->assertSame(41000, $row['suggested']);
        $this->assertSame(150000, $row['max']);
        $this->assertStringContainsString('中央値', $row['basis']);
    }

    public function test_固定費は直近の実績をもとに提案する(): void
    {
        $rent = $this->rent();

        // 5月に更新して家賃が上がった。中央値だと古い安い金額に引きずられる
        $this->spend($rent, [
            '2026-02' => 80000,
            '2026-03' => 80000,
            '2026-04' => 80000,
            '2026-05' => 85000,
            '2026-06' => 85000,
            '2026-07' => 85000,
        ]);

        $row = $this->rowFor($this->service()->suggest($this->target), '家賃');

        $this->assertSame(85000, $row['suggested']);
        $this->assertStringContainsString('固定費', $row['basis']);
    }

    public function test_対象月の実績は提案に含めない(): void
    {
        $food = $this->food();

        $this->spend($food, [
            '2026-02' => 30000,
            '2026-03' => 30000,
            '2026-04' => 30000,
            '2026-05' => 30000,
            '2026-06' => 30000,
            '2026-07' => 30000,
        ]);

        // 対象月（8月）は途中。混ざれば中央値が動くくらい極端な値にしておく
        $this->spend($food, ['2026-08' => 500000]);

        $suggestion = $this->service()->suggest($this->target);
        $row = $this->rowFor($suggestion, '食費');

        $this->assertSame(6, $suggestion['available']);
        $this->assertSame(30000, $row['median']);
        $this->assertSame(7, Transaction::query()->count());
        $this->assertNotContains('2026-08', array_keys($row['series']));
    }

    public function test_実績が3ヶ月未満なら提案しない(): void
    {
        $food = $this->food();

        $this->spend($food, ['2026-06' => 30000, '2026-07' => 32000]);

        $suggestion = $this->service()->suggest($this->target);

        $this->assertFalse($suggestion['enough']);
        $this->assertSame(2, $suggestion['available']);
        $this->assertNull($suggestion['overall']);
        $this->assertTrue($suggestion['categories']->isEmpty());
    }

    public function test_記録していない月は0円として数えない(): void
    {
        $food = $this->food();

        // 3・4月は家計簿をつけていない。0円として混ぜると中央値が半分近くまで下がる
        $this->spend($food, [
            '2026-02' => 30000,
            '2026-05' => 32000,
            '2026-06' => 31000,
            '2026-07' => 33000,
        ]);

        $suggestion = $this->service()->suggest($this->target);
        $row = $this->rowFor($suggestion, '食費');

        $this->assertSame(4, $suggestion['available']);
        $this->assertSame(31500, $row['median']);
        $this->assertSame(['2026-02', '2026-05', '2026-06', '2026-07'], array_keys($row['series']));
    }

    public function test_その月に使わなかったカテゴリは0円として数える(): void
    {
        $food = $this->food();
        $hobby = Category::factory()->create(['name' => '娯楽', 'sort_order' => 2]);

        // 家計簿はつけている（食費がある）が、娯楽を使わなかった月がある
        $this->spend($food, ['2026-05' => 30000, '2026-06' => 30000, '2026-07' => 30000]);
        $this->spend($hobby, ['2026-05' => 12000, '2026-07' => 8000]);

        $row = $this->rowFor($this->service()->suggest($this->target), '娯楽');

        // 0, 8000, 12000 の中央値は 8000
        $this->assertSame(8000, $row['median']);
        $this->assertSame(0, $row['min']);
    }

    public function test_一度も使っていないカテゴリは提案に出さない(): void
    {
        $food = $this->food();
        Category::factory()->create(['name' => '医療費', 'sort_order' => 3]);

        $this->spend($food, ['2026-05' => 30000, '2026-06' => 30000, '2026-07' => 30000]);

        $suggestion = $this->service()->suggest($this->target);

        $this->assertNull($suggestion['categories']->firstWhere('name', '医療費'));
    }

    public function test_支出全体は月ごとの合計の中央値になる(): void
    {
        $food = $this->food();
        $hobby = Category::factory()->create(['name' => '娯楽', 'sort_order' => 2]);

        $this->spend($food, ['2026-05' => 30000, '2026-06' => 31000, '2026-07' => 32000]);
        $this->spend($hobby, ['2026-05' => 10000, '2026-06' => 5000, '2026-07' => 20000]);

        $suggestion = $this->service()->suggest($this->target);

        // 月合計は 40000 / 36000 / 52000 → 中央値 40000
        $this->assertSame(40000, $suggestion['overall']['median']);
        $this->assertSame(40000, $suggestion['overall']['suggested']);
    }

    public function test_カテゴリ未設定の支出は全体にだけ数える(): void
    {
        $food = $this->food();

        $this->spend($food, ['2026-05' => 30000, '2026-06' => 30000, '2026-07' => 30000]);

        foreach (['2026-05', '2026-06', '2026-07'] as $month) {
            Transaction::factory()->create([
                'transaction_date' => $month.'-20',
                'type' => 'expense',
                'category_id' => null,
                'amount' => 5000,
            ]);
        }

        $suggestion = $this->service()->suggest($this->target);

        $this->assertSame(15000, $suggestion['uncategorized']);
        $this->assertSame(35000, $suggestion['overall']['median']);
        $this->assertSame(30000, $this->rowFor($suggestion, '食費')['median']);
    }

    public function test_収入は提案に含めない(): void
    {
        $food = $this->food();
        $salary = Category::factory()->create(['name' => '給与', 'type' => 'income', 'sort_order' => 9]);

        $this->spend($food, ['2026-05' => 30000, '2026-06' => 30000, '2026-07' => 30000]);

        foreach (['2026-05', '2026-06', '2026-07'] as $month) {
            Transaction::factory()->create([
                'transaction_date' => $month.'-25',
                'type' => 'income',
                'category_id' => $salary->id,
                'amount' => 300000,
            ]);
        }

        $suggestion = $this->service()->suggest($this->target);

        $this->assertSame(30000, $suggestion['overall']['median']);
        $this->assertNull($suggestion['categories']->firstWhere('name', '給与'));
    }

    public function test_いまの予算との差額を出す(): void
    {
        $food = $this->food();

        $this->spend($food, ['2026-05' => 40000, '2026-06' => 41000, '2026-07' => 42000]);

        Budget::factory()->create(['category_id' => $food->id, 'month' => null, 'amount' => 35000]);

        $row = $this->rowFor($this->service()->suggest($this->target), '食費');

        $this->assertSame(35000, $row['current']);
        $this->assertSame(41000, $row['suggested']);
        $this->assertSame(6000, $row['diff']);
    }

    public function test_月指定の予算はデフォルト予算より優先される(): void
    {
        $food = $this->food();

        $this->spend($food, ['2026-05' => 40000, '2026-06' => 41000, '2026-07' => 42000]);

        Budget::factory()->create(['category_id' => $food->id, 'month' => null, 'amount' => 35000]);
        Budget::factory()->create(['category_id' => $food->id, 'month' => '2026-08-01', 'amount' => 50000]);

        $row = $this->rowFor($this->service()->suggest($this->target), '食費');

        $this->assertSame(50000, $row['current']);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function 丸め方(): array
    {
        return [
            '1万円以上は1000円単位' => [32480, 33000],
            'ちょうどなら増やさない' => [33000, 33000],
            '1000円台は100円単位' => [4320, 4400],
            '1000円未満は10円単位' => [812, 820],
            '0円は0円' => [0, 0],
            '負の値は0円' => [-500, 0],
        ];
    }

    #[DataProvider('丸め方')]
    public function test_予算として書きやすい数字に丸める(int $amount, int $expected): void
    {
        $this->assertSame($expected, BudgetSuggestionService::roundUp($amount));
    }

    public function test_提案画面が開ける(): void
    {
        $food = $this->food();
        $this->spend($food, ['2026-05' => 30000, '2026-06' => 31000, '2026-07' => 32000]);

        $this->get(route('budgets.suggestions', ['month' => '2026-08']))
            ->assertOk()
            ->assertSee('食費')
            ->assertSee('過去の実績から予算を提案');
    }

    public function test_提案画面は月がおかしくても500にならない(): void
    {
        $this->get('/budgets/suggestions?month[]=x')->assertOk();
        $this->get('/budgets/suggestions?month=9999-99')->assertOk();
        $this->get('/budgets/suggestions?lookback[]=1')->assertOk();
        $this->get('/budgets/suggestions?lookback=99999')->assertOk();
    }

    public function test_予算管理画面も月がおかしくても500にならない(): void
    {
        $this->get('/budgets?month[]=x')->assertOk();
        $this->get('/budgets/create?month[]=x')->assertOk();
    }

    public function test_選んだ提案だけを予算として登録する(): void
    {
        $food = $this->food();
        $hobby = Category::factory()->create(['name' => '娯楽', 'sort_order' => 2]);

        $this->post(route('budgets.suggestions.apply'), [
            'month' => '2026-08',
            'scope' => 'default',
            'selected' => ['overall', (string) $food->id],
            'amounts' => [
                'overall' => 50000,
                (string) $food->id => 41000,
                (string) $hobby->id => 9000,
            ],
        ])->assertRedirect(route('budgets.index', ['month' => '2026-08']));

        $this->assertSame(2, Budget::query()->count());
        $this->assertSame(50000, Budget::query()->whereNull('category_id')->first()->amount);
        $this->assertSame(41000, Budget::query()->where('category_id', $food->id)->first()->amount);
        $this->assertNull(Budget::query()->where('category_id', $hobby->id)->first());
    }

    public function test_月だけの予算として登録できる(): void
    {
        $food = $this->food();

        $this->post(route('budgets.suggestions.apply'), [
            'month' => '2026-08',
            'scope' => 'month',
            'selected' => [(string) $food->id],
            'amounts' => [(string) $food->id => 41000],
        ])->assertRedirect();

        $budget = Budget::query()->where('category_id', $food->id)->first();

        $this->assertNotNull($budget);
        $this->assertSame('2026-08-01', $budget->month->toDateString());
    }

    public function test_既存の予算を上書きしてもメモは消さない(): void
    {
        $food = $this->food();

        Budget::factory()->create([
            'category_id' => $food->id,
            'month' => null,
            'amount' => 35000,
            'memo' => '外食は別枠',
        ]);

        $this->post(route('budgets.suggestions.apply'), [
            'month' => '2026-08',
            'scope' => 'default',
            'selected' => [(string) $food->id],
            'amounts' => [(string) $food->id => 41000],
        ])->assertRedirect();

        $budget = Budget::query()->where('category_id', $food->id)->first();

        $this->assertSame(1, Budget::query()->count());
        $this->assertSame(41000, $budget->amount);
        $this->assertSame('外食は別枠', $budget->memo);
    }

    public function test_収入カテゴリのidを送っても登録しない(): void
    {
        $salary = Category::factory()->create(['name' => '給与', 'type' => 'income']);

        $this->post(route('budgets.suggestions.apply'), [
            'month' => '2026-08',
            'scope' => 'default',
            'selected' => [(string) $salary->id],
            'amounts' => [(string) $salary->id => 300000],
        ])->assertSessionHasErrors('selected');

        $this->assertSame(0, Budget::query()->count());
    }

    public function test_存在しないカテゴリのidを送っても登録しない(): void
    {
        $this->post(route('budgets.suggestions.apply'), [
            'month' => '2026-08',
            'scope' => 'default',
            'selected' => ['999999'],
            'amounts' => ['999999' => 10000],
        ])->assertSessionHasErrors('selected');

        $this->assertSame(0, Budget::query()->count());
    }

    public function test_何も選ばずに送ると登録しない(): void
    {
        $this->post(route('budgets.suggestions.apply'), [
            'month' => '2026-08',
            'scope' => 'default',
            'amounts' => ['overall' => 50000],
        ])->assertSessionHasErrors('selected');

        $this->assertSame(0, Budget::query()->count());
    }

    public function test_未ログインなら提案画面は開けない(): void
    {
        Auth::logout();

        $this->get('/budgets/suggestions')->assertRedirect('/login');
        $this->post(route('budgets.suggestions.apply'), [])->assertRedirect('/login');
    }

    public function test_未分類だけの月はカテゴリの実績に数えない(): void
    {
        $food = $this->food();

        // 2〜4月はカード明細を取り込んだだけでカテゴリ未設定。
        // これを「食費0円の月」として混ぜると、中央値が半分近くまで落ちる
        foreach (['2026-02', '2026-03', '2026-04'] as $month) {
            Transaction::factory()->create([
                'transaction_date' => $month.'-10',
                'type' => 'expense',
                'category_id' => null,
                'amount' => 100000,
            ]);
        }

        $this->spend($food, ['2026-05' => 30000, '2026-06' => 30000, '2026-07' => 30000]);

        $suggestion = $this->service()->suggest($this->target);
        $row = $this->rowFor($suggestion, '食費');

        // 支出全体は6ヶ月ぶん、カテゴリ別は3ヶ月ぶんで見る
        $this->assertSame(6, $suggestion['available']);
        $this->assertSame(['2026-05', '2026-06', '2026-07'], $suggestion['category_months']);
        $this->assertSame(30000, $row['median']);
        $this->assertSame(30000, $row['suggested']);
    }

    public function test_提案が0円になるカテゴリは出さない(): void
    {
        $food = $this->food();
        $medical = Category::factory()->create(['name' => '医療費', 'sort_order' => 4]);

        $this->spend($food, [
            '2026-02' => 30000, '2026-03' => 30000, '2026-04' => 30000,
            '2026-05' => 30000, '2026-06' => 30000, '2026-07' => 30000,
        ]);

        // 6ヶ月で1回だけ。中央値は0円になる
        $this->spend($medical, ['2026-07' => 30000]);

        $suggestion = $this->service()->suggest($this->target);

        // 0円の予算を登録すると「消化率0%＝順調」と表示され、予算が無いより悪くなる
        $this->assertNull($suggestion['categories']->firstWhere('name', '医療費'));
    }

    public function test_0円は予算として登録しない(): void
    {
        $food = $this->food();

        $this->post(route('budgets.suggestions.apply'), [
            'month' => '2026-08',
            'scope' => 'default',
            'selected' => [(string) $food->id],
            'amounts' => [(string) $food->id => 0],
        ])->assertSessionHasErrors('selected');

        $this->assertSame(0, Budget::query()->count());
    }

    public function test_登録に失敗しても直した金額は残る(): void
    {
        $food = $this->food();

        $this->post(route('budgets.suggestions.apply'), [
            'month' => '2026-08',
            'scope' => 'default',
            'selected' => [(string) $food->id],
            'amounts' => [(string) $food->id => 0],
        ])->assertSessionHasErrors('selected');

        // withInput が無いと、手で直した金額が全部消えて提案値に戻ってしまう
        $this->assertEquals(0, session()->getOldInput('amounts.'.$food->id));
        $this->assertSame([(string) $food->id], session()->getOldInput('selected'));
    }

    public function test_デフォルト予算を入れても月指定があればそれを知らせる(): void
    {
        $food = $this->food();

        Budget::factory()->create(['category_id' => $food->id, 'month' => '2026-08-01', 'amount' => 50000]);

        $this->post(route('budgets.suggestions.apply'), [
            'month' => '2026-08',
            'scope' => 'default',
            'selected' => [(string) $food->id],
            'amounts' => [(string) $food->id => 41000],
        ])->assertRedirect();

        // 「登録したのに画面の数字が変わらない」を黙って起こさない
        $this->assertStringContainsString('月指定の予算', session('status'));
    }

    public function test_月指定で登録すれば余計な注意書きは出ない(): void
    {
        $food = $this->food();

        $this->post(route('budgets.suggestions.apply'), [
            'month' => '2026-08',
            'scope' => 'month',
            'selected' => [(string) $food->id],
            'amounts' => [(string) $food->id => 41000],
        ])->assertRedirect();

        $this->assertStringNotContainsString('月指定の予算', session('status'));
    }

    public function test_月指定の予算は二度目も上書きになる(): void
    {
        $food = $this->food();

        foreach ([41000, 45000] as $amount) {
            $this->post(route('budgets.suggestions.apply'), [
                'month' => '2026-08',
                'scope' => 'month',
                'selected' => [(string) $food->id],
                'amounts' => [(string) $food->id => $amount],
            ])->assertRedirect();
        }

        $this->assertSame(1, Budget::query()->count());
        $this->assertSame(45000, Budget::query()->first()->amount);
    }

    public function test_同じカテゴリを表記違いで送っても1件しか登録しない(): void
    {
        $food = $this->food();

        // "7" と "07" は array_unique では別物として残る
        $this->post(route('budgets.suggestions.apply'), [
            'month' => '2026-08',
            'scope' => 'default',
            'selected' => [(string) $food->id, str_pad((string) $food->id, 3, '0', STR_PAD_LEFT)],
            'amounts' => [(string) $food->id => 41000],
        ])->assertRedirect();

        $this->assertSame(1, Budget::query()->count());
        $this->assertStringContainsString('1 件登録しました', session('status'));
    }

    public function test_金額が空の行は見送ったと知らせる(): void
    {
        $food = $this->food();
        $hobby = Category::factory()->create(['name' => '娯楽', 'sort_order' => 2]);

        $this->post(route('budgets.suggestions.apply'), [
            'month' => '2026-08',
            'scope' => 'default',
            'selected' => [(string) $food->id, (string) $hobby->id],
            'amounts' => [(string) $food->id => 41000, (string) $hobby->id => null],
        ])->assertRedirect();

        $this->assertSame(1, Budget::query()->count());
        $this->assertStringContainsString('見送りました', session('status'));
    }

    public function test_選択肢に無いさかのぼり月数は既定に戻す(): void
    {
        $food = $this->food();
        $this->spend($food, ['2026-05' => 30000, '2026-06' => 30000, '2026-07' => 30000]);

        // 1ヶ月では何ヶ月待っても提案できないので、既定(6)に戻す
        $this->get('/budgets/suggestions?month=2026-08&lookback=1')
            ->assertOk()
            ->assertSee('食費');

        $this->get('/budgets/suggestions?month=2026-08&lookback=12')
            ->assertOk()
            ->assertSee('食費');
    }

    public function test_0円予算でも使っていれば予算超過として扱う(): void
    {
        $food = $this->food();

        Budget::factory()->create(['category_id' => $food->id, 'month' => null, 'amount' => 0]);

        Transaction::factory()->create([
            'transaction_date' => '2026-08-05',
            'type' => 'expense',
            'category_id' => $food->id,
            'amount' => 40000,
        ]);

        $summary = app(\App\Services\BudgetService::class)->summary($this->target);
        $row = $summary['categories']->firstWhere('name', '食費');

        // 消化率0%＝「順調」にしてしまうと、使っているのにアラートが出ない
        $this->assertNotNull($row);
        $this->assertSame(\App\Services\BudgetService::STATUS_OVER, $row['status']);
    }

    public function test_範囲外の月の予算でも編集して保存できる(): void
    {
        $food = $this->food();

        // 直接作る（MonthParser の範囲外＝2000年より前）
        $budget = Budget::factory()->create([
            'category_id' => $food->id,
            'month' => '1998-04-01',
            'amount' => 10000,
        ]);

        $this->put(route('budgets.update', $budget), [
            'category_id' => $food->id,
            'month' => '1998-04',
            'amount' => 12000,
        ])->assertRedirect(route('budgets.index'));

        $budget->refresh();

        $this->assertSame(12000, $budget->amount);
        $this->assertSame('1998-04-01', $budget->month->toDateString());
    }

    public function test_範囲外の新しい月は登録できない(): void
    {
        $food = $this->food();

        $this->post(route('budgets.store'), [
            'category_id' => $food->id,
            'month' => '1998-04',
            'amount' => 12000,
        ])->assertSessionHasErrors('month');

        $this->assertSame(0, Budget::query()->count());
    }
}
