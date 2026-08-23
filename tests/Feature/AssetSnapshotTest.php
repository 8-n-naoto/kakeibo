<?php

namespace Tests\Feature;

use App\Models\AssetSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_資産スナップショットの一覧が表示できる(): void
    {
        AssetSnapshot::factory()->create(['snapshot_date' => now()->startOfMonth()]);

        $response = $this->get(route('assets.index'));

        $response->assertOk();
    }

    public function test_資産スナップショットを登録できる(): void
    {
        $response = $this->post(route('assets.store'), [
            'snapshot_date' => now()->format('Y-m'),
            'cash_amount' => 1000000,
            'nisa_amount' => 500000,
            'ideco_amount' => 200000,
            'other_investment_amount' => 0,
        ]);

        $response->assertRedirect(route('assets.index'));
        $this->assertDatabaseHas('asset_snapshots', [
            'cash_amount' => 1000000,
            'nisa_amount' => 500000,
        ]);
    }

    public function test_同じ月に登録すると上書きされる(): void
    {
        $month = now()->format('Y-m');

        $this->post(route('assets.store'), [
            'snapshot_date' => $month,
            'cash_amount' => 1000000,
            'nisa_amount' => 0,
            'ideco_amount' => 0,
            'other_investment_amount' => 0,
        ]);

        $this->post(route('assets.store'), [
            'snapshot_date' => $month,
            'cash_amount' => 1200000,
            'nisa_amount' => 0,
            'ideco_amount' => 0,
            'other_investment_amount' => 0,
        ]);

        $this->assertSame(1, AssetSnapshot::count());
        $this->assertDatabaseHas('asset_snapshots', ['cash_amount' => 1200000]);
    }

    public function test_総資産は各内訳の合計になる(): void
    {
        $snapshot = AssetSnapshot::factory()->create([
            'cash_amount' => 1000000,
            'nisa_amount' => 500000,
            'ideco_amount' => 300000,
            'other_investment_amount' => 200000,
        ]);

        $this->assertSame(2000000, $snapshot->total_amount);
    }
}
