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

    public function test_上書きを選べば同じ年度種別で登録し直せる(): void
    {
        $payload = [
            'account_type' => 'nisa_growth',
            'fiscal_year' => now()->year,
            'annual_limit' => 2400000,
            'contributed_amount' => 500000,
            'evaluation_amount' => 520000,
        ];

        $this->post(route('investment-accounts.store'), $payload);
        $this->post(route('investment-accounts.store'), array_merge($payload, [
            'contributed_amount' => 800000,
            'overwrite' => '1',
        ]));

        $this->assertSame(1, InvestmentAccount::count());
        $this->assertDatabaseHas('investment_accounts', ['contributed_amount' => 800000]);
    }

    public function test_同じ年度種別を黙って上書きしない(): void
    {
        $payload = [
            'account_type' => 'nisa_growth',
            'fiscal_year' => now()->year,
            'annual_limit' => 2400000,
            'contributed_amount' => 500000,
            'evaluation_amount' => 520000,
        ];

        $this->post(route('investment-accounts.store'), $payload);

        $response = $this->from(route('investment-accounts.create'))->post(
            route('investment-accounts.store'),
            array_merge($payload, ['contributed_amount' => 800000]),
        );

        $response->assertSessionHasErrors('fiscal_year');
        $this->assertSame(1, InvestmentAccount::count());
        $this->assertDatabaseHas('investment_accounts', ['contributed_amount' => 500000]);
    }

    public function test_編集で既存とぶつかる年度にはできない(): void
    {
        // (account_type, fiscal_year) は unique。素通りすると 500 になり入力も消える
        $older = InvestmentAccount::factory()->create(['account_type' => 'nisa_growth', 'fiscal_year' => 2025]);
        InvestmentAccount::factory()->create(['account_type' => 'nisa_growth', 'fiscal_year' => 2026]);

        $response = $this->from(route('investment-accounts.edit', $older))->put(
            route('investment-accounts.update', $older),
            [
                'account_type' => 'nisa_growth',
                'fiscal_year' => 2026,
                'annual_limit' => 2400000,
                'contributed_amount' => 100000,
                'evaluation_amount' => 100000,
            ],
        );

        $response->assertSessionHasErrors('fiscal_year');
        $this->assertSame(2025, $older->fresh()->fiscal_year);
        $this->assertSame(2, InvestmentAccount::count());
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
