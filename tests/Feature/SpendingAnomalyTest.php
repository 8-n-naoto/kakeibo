<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\SpendingAnomalyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 予算を決めていないカテゴリの急増と、単発の高額支出の検知。
 */
class SpendingAnomalyTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->month = Carbon::createFromFormat('!Y-m', '2026-08');
    }

    private function detect()
    {
        return app(SpendingAnomalyService::class)->detect($this->month);
    }

    private function spend(Category $category, string $date, int $amount, ?string $shop = null): Transaction
    {
        return Transaction::factory()->create([
            'category_id' => $category->id,
            'type' => 'expense',
            'transaction_date' => $date,
            'amount' => $amount,
            'shop_name' => $shop,
        ]);
    }

    public function test_平均より大きく増えたカテゴリを検知する(): void
    {
        $category = Category::factory()->create(['name' => '娯楽・趣味']);

        // 過去3ヶ月は毎月10,000円
        $this->spend($category, '2026-05-10', 10000);
        $this->spend($category, '2026-06-10', 10000);
        $this->spend($category, '2026-07-10', 10000);
        // 当月は40,000円
        $this->spend($category, '2026-08-10', 40000);

        $anomalies = $this->detect();

        $this->assertCount(1, $anomalies);
        $this->assertSame(SpendingAnomalyService::TYPE_CATEGORY_SPIKE, $anomalies[0]['type']);
        $this->assertSame('娯楽・趣味', $anomalies[0]['name']);
        $this->assertSame(10000, $anomalies[0]['average']);
        $this->assertSame(30000, $anomalies[0]['difference']);
        $this->assertSame(4.0, $anomalies[0]['ratio']);
    }

    public function test_実績が2ヶ月しかないカテゴリでは騒がない(): void
    {
        $category = Category::factory()->create(['name' => '娯楽・趣味']);

        $this->spend($category, '2026-06-10', 10000);
        $this->spend($category, '2026-07-10', 10000);
        $this->spend($category, '2026-08-10', 40000);

        $this->assertCount(0, $this->detect());
    }

    public function test_差額が小さければ騒がない(): void
    {
        $category = Category::factory()->create(['name' => '日用品']);

        $this->spend($category, '2026-05-10', 1000);
        $this->spend($category, '2026-06-10', 1000);
        $this->spend($category, '2026-07-10', 1000);
        // 3倍だが差額は2,000円なので通知しない
        $this->spend($category, '2026-08-10', 3000);

        $this->assertCount(0, $this->detect());
    }

    public function test_いつもどおりの支出は検知しない(): void
    {
        $category = Category::factory()->create(['name' => '食費']);

        foreach (['2026-05-10', '2026-06-10', '2026-07-10', '2026-08-10'] as $date) {
            $this->spend($category, $date, 40000);
        }

        $this->assertCount(0, $this->detect());
    }

    public function test_単発の高額支出を検知する(): void
    {
        $category = Category::factory()->create(['name' => '日用品']);

        // 同カテゴリに5件以上の実績（平均 2,000円）
        foreach (['2026-05-01', '2026-05-15', '2026-06-01', '2026-06-15', '2026-07-01'] as $date) {
            $this->spend($category, $date, 2000);
        }

        $this->spend($category, '2026-08-10', 30000, 'ニトリ');

        $anomalies = $this->detect();
        $large = $anomalies->firstWhere('type', SpendingAnomalyService::TYPE_LARGE_TRANSACTION);

        $this->assertNotNull($large);
        $this->assertSame(30000, $large['amount']);
        $this->assertSame(2000, $large['average']);
        $this->assertSame('ニトリ', $large['transaction']->shop_name);
    }

    public function test_1万円未満は高額とみなさない(): void
    {
        $category = Category::factory()->create(['name' => '日用品']);

        foreach (['2026-05-01', '2026-05-15', '2026-06-01', '2026-06-15', '2026-07-01'] as $date) {
            $this->spend($category, $date, 500);
        }

        $this->spend($category, '2026-08-10', 9000);

        $anomalies = $this->detect();

        $this->assertNull($anomalies->firstWhere('type', SpendingAnomalyService::TYPE_LARGE_TRANSACTION));
    }

    public function test_収入は対象外(): void
    {
        $category = Category::factory()->create(['name' => '給与', 'type' => 'income']);

        foreach (['2026-05-25', '2026-06-25', '2026-07-25'] as $date) {
            Transaction::factory()->create([
                'category_id' => $category->id,
                'type' => 'income',
                'transaction_date' => $date,
                'amount' => 300000,
            ]);
        }

        Transaction::factory()->create([
            'category_id' => $category->id,
            'type' => 'income',
            'transaction_date' => '2026-08-25',
            'amount' => 900000,
        ]);

        $this->assertCount(0, $this->detect());
    }

    public function test_当月に支出が無ければ何も返さない(): void
    {
        $category = Category::factory()->create(['name' => '食費']);

        $this->spend($category, '2026-05-10', 10000);
        $this->spend($category, '2026-06-10', 10000);
        $this->spend($category, '2026-07-10', 10000);

        $this->assertCount(0, $this->detect());
    }

    public function test_ダッシュボードに気になる支出が出る(): void
    {
        $category = Category::factory()->create(['name' => '娯楽・趣味']);

        $this->spend($category, '2026-05-10', 10000);
        $this->spend($category, '2026-06-10', 10000);
        $this->spend($category, '2026-07-10', 10000);
        $this->spend($category, '2026-08-10', 40000);

        $response = $this->get(route('dashboard', ['month' => '2026-08']));

        $response->assertOk();
        $response->assertSee('今月の気になる支出');
        $response->assertSee('娯楽・趣味');
    }

    public function test_異常が無ければダッシュボードに出ない(): void
    {
        $response = $this->get(route('dashboard', ['month' => '2026-08']));

        $response->assertOk();
        $response->assertDontSee('今月の気になる支出');
    }
}
