<?php

namespace Tests\Feature;

use App\Models\InvestmentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestmentAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_投資口座の一覧が表示できる(): void
    {
        InvestmentAccount::factory()->create();

        $response = $this->get(route('investment-accounts.index'));

        $response->assertOk();
    }

    public function test_投資口座を登録できる(): void
    {
        $response = $this->post(route('investment-accounts.store'), [
            'account_type' => 'ideco',
            'fiscal_year' => now()->year,
            'annual_limit' => 276000,
            'contributed_amount' => 138000,
            'evaluation_amount' => 150000,
        ]);

        $response->assertRedirect(route('investment-accounts.index', ['fiscal_year' => now()->year]));
        $this->assertDatabaseHas('investment_accounts', ['account_type' => 'ideco']);
    }

    public function test_同じ年度種別で登録すると上書きされる(): void
    {
        $payload = [
            'account_type' => 'nisa_growth',
            'fiscal_year' => now()->year,
            'annual_limit' => 2400000,
            'contributed_amount' => 500000,
            'evaluation_amount' => 520000,
        ];

        $this->post(route('investment-accounts.store'), $payload);
        $this->post(route('investment-accounts.store'), array_merge($payload, ['contributed_amount' => 800000]));

        $this->assertSame(1, InvestmentAccount::count());
        $this->assertDatabaseHas('investment_accounts', ['contributed_amount' => 800000]);
    }

    public function test_年間投資枠消化率が計算される(): void
    {
        $account = InvestmentAccount::factory()->create([
            'annual_limit' => 1200000,
            'contributed_amount' => 300000,
        ]);

        $this->assertSame(25.0, $account->usage_rate);
    }

    public function test_含み損益が計算される(): void
    {
        $account = InvestmentAccount::factory()->create([
            'contributed_amount' => 500000,
            'evaluation_amount' => 480000,
        ]);

        $this->assertSame(-20000, $account->gain_loss);
    }
}
