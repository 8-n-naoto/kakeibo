<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Services\RecurringTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 定期支出（家賃・サブスクなど）の登録と月次計上。
 */
class RecurringTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RecurringTransactionService
    {
        return app(RecurringTransactionService::class);
    }

    public function test_一覧が表示できる(): void
    {
        RecurringTransaction::factory()->create(['name' => '家賃']);

        $response = $this->get(route('recurring.index'));

        $response->assertOk();
        $response->assertSee('家賃');
    }

    public function test_定期支出を登録できる(): void
    {
        $category = Category::factory()->create(['name' => '住居費']);

        $response = $this->post(route('recurring.store'), [
            'name' => '家賃',
            'type' => 'expense',
            'category_id' => $category->id,
            'amount' => 85000,
            'day_of_month' => 27,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('recurring.index'));
        $this->assertDatabaseHas('recurring_transactions', [
            'name' => '家賃',
            'amount' => 85000,
            'day_of_month' => 27,
            'is_active' => true,
        ]);
    }

    public function test_終了日が開始日より前だと登録できない(): void
    {
        // 保存できてしまうと appliesTo() が常に false になり、
        // 家賃が黙って計上されなくなる（画面には何も出ない）
        $response = $this->from(route('recurring.create'))->post(route('recurring.store'), [
            'name' => '家賃',
            'type' => 'expense',
            'amount' => 85000,
            'day_of_month' => 27,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-07-31',
        ]);

        $response->assertSessionHasErrors('ends_on');
        $this->assertSame(0, RecurringTransaction::count());
    }

    public function test_期間が逆転している既存の定期支出は一覧で警告する(): void
    {
        // 新規登録では弾くようにしたが、それ以前に保存されたものはここでしか気づけない
        RecurringTransaction::factory()->create([
            'name' => '解約済みのサブスク',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-07-31',
        ]);

        $response = $this->get(route('recurring.index'));

        $response->assertOk();
        $response->assertSee('終了日が開始日より前です。この設定では計上されません');
    }

    public function test_終了日と開始日が同じ日なら登録できる(): void
    {
        $this->post(route('recurring.store'), [
            'name' => '単発の引き落とし',
            'type' => 'expense',
            'amount' => 1000,
            'day_of_month' => 1,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-01',
        ])->assertRedirect(route('recurring.index'));

        $this->assertSame(1, RecurringTransaction::count());
    }

    public function test_計上日が32日以上だと登録できない(): void
    {
        $response = $this->from(route('recurring.create'))->post(route('recurring.store'), [
            'name' => '家賃',
            'type' => 'expense',
            'amount' => 85000,
            'day_of_month' => 32,
        ]);

        $response->assertSessionHasErrors('day_of_month');
        $this->assertSame(0, RecurringTransaction::count());
    }

    public function test_当月分をまとめて計上できる(): void
    {
        $category = Category::factory()->create(['name' => '住居費']);
        RecurringTransaction::factory()->create([
            'name' => '家賃',
            'category_id' => $category->id,
            'amount' => 85000,
            'day_of_month' => 27,
        ]);
        RecurringTransaction::factory()->create([
            'name' => 'Netflix',
            'category_id' => $category->id,
            'amount' => 1590,
            'day_of_month' => 5,
        ]);

        $response = $this->post(route('recurring.post'), ['month' => '2026-08']);

        $response->assertRedirect(route('recurring.index', ['month' => '2026-08']));
        $this->assertSame(2, Transaction::count());
        $this->assertDatabaseHas('transactions', [
            'transaction_date' => '2026-08-27',
            'amount' => 85000,
            'memo' => '家賃',
        ]);
        $this->assertDatabaseHas('transactions', [
            'transaction_date' => '2026-08-05',
            'amount' => 1590,
            'memo' => 'Netflix',
        ]);
    }

    public function test_二度計上しても取引は増えない(): void
    {
        RecurringTransaction::factory()->create(['name' => '家賃', 'amount' => 85000, 'day_of_month' => 27]);

        $this->post(route('recurring.post'), ['month' => '2026-08']);
        $this->post(route('recurring.post'), ['month' => '2026-08']);

        $this->assertSame(1, Transaction::count());
    }

    public function test_停止中の定期支出は計上されない(): void
    {
        RecurringTransaction::factory()->create(['name' => '解約済みサブスク', 'is_active' => false]);

        $this->post(route('recurring.post'), ['month' => '2026-08']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_開始日より前の月は計上されない(): void
    {
        RecurringTransaction::factory()->create([
            'name' => '来月から',
            'starts_on' => '2026-09-01',
            'day_of_month' => 1,
        ]);

        $this->post(route('recurring.post'), ['month' => '2026-08']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_終了日より後の月は計上されない(): void
    {
        RecurringTransaction::factory()->create([
            'name' => '解約した',
            'ends_on' => '2026-07-31',
            'day_of_month' => 1,
        ]);

        $this->post(route('recurring.post'), ['month' => '2026-08']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_月末を超える計上日はその月の末日になる(): void
    {
        $rule = RecurringTransaction::factory()->create(['name' => '月末払い', 'day_of_month' => 31]);

        $this->service()->post(Carbon::createFromFormat('!Y-m', '2026-02'));

        $this->assertDatabaseHas('transactions', [
            'recurring_transaction_id' => $rule->id,
            'transaction_date' => '2026-02-28',
        ]);
    }

    public function test_未計上の一覧が取得できる(): void
    {
        $posted = RecurringTransaction::factory()->create(['name' => '計上済み', 'day_of_month' => 1]);
        RecurringTransaction::factory()->create(['name' => '未計上', 'day_of_month' => 2]);

        $month = Carbon::createFromFormat('!Y-m', '2026-08');

        Transaction::create([
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'amount' => 1000,
            'recurring_transaction_id' => $posted->id,
        ]);

        $pending = $this->service()->pendingFor($month);

        $this->assertSame(['未計上'], $pending->pluck('name')->all());
    }

    public function test_ダッシュボードに未計上の案内が出る(): void
    {
        RecurringTransaction::factory()->create(['name' => '家賃', 'amount' => 85000, 'day_of_month' => 27]);

        $response = $this->get(route('dashboard', ['month' => '2026-08']));

        $response->assertOk();
        $response->assertSee('未計上の定期支出');
        $response->assertSee('家賃');
    }

    public function test_計上済みなら案内は出ない(): void
    {
        RecurringTransaction::factory()->create(['name' => '家賃', 'amount' => 85000, 'day_of_month' => 27]);

        $this->post(route('recurring.post'), ['month' => '2026-08']);

        $response = $this->get(route('dashboard', ['month' => '2026-08']));

        $response->assertOk();
        $response->assertDontSee('未計上の定期支出');
    }

    public function test_定期支出を削除しても計上済みの取引は残る(): void
    {
        $rule = RecurringTransaction::factory()->create(['name' => '家賃', 'amount' => 85000]);
        $this->post(route('recurring.post'), ['month' => '2026-08']);

        $this->delete(route('recurring.destroy', $rule));

        $this->assertSame(0, RecurringTransaction::count());
        $this->assertSame(1, Transaction::count());
        $this->assertNull(Transaction::first()->recurring_transaction_id);
    }

    public function test_artisanコマンドで計上できる(): void
    {
        RecurringTransaction::factory()->create(['name' => '家賃', 'amount' => 85000, 'day_of_month' => 27]);

        $this->artisan('recurring:post', ['--month' => '2026-08'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('transactions', [
            'transaction_date' => '2026-08-27',
            'amount' => 85000,
        ]);
    }
}
