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

    public function test_同じ月に上書きを選べば置き換わる(): void
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
            'overwrite' => '1',
        ]);

        $this->assertSame(1, AssetSnapshot::count());
        $this->assertDatabaseHas('asset_snapshots', ['cash_amount' => 1200000]);
    }

    public function test_同じ月を黙って上書きしない(): void
    {
        // 資産推移は「その月いくらだったか」の記録。置き換えは明示的に選ばせる
        $month = now()->format('Y-m');

        $this->post(route('assets.store'), [
            'snapshot_date' => $month,
            'cash_amount' => 1000000,
            'nisa_amount' => 0,
            'ideco_amount' => 0,
            'other_investment_amount' => 0,
        ]);

        $response = $this->from(route('assets.create'))->post(route('assets.store'), [
            'snapshot_date' => $month,
            'cash_amount' => 1200000,
            'nisa_amount' => 0,
            'ideco_amount' => 0,
            'other_investment_amount' => 0,
        ]);

        $response->assertSessionHasErrors('snapshot_date');
        $this->assertSame(1, AssetSnapshot::count());
        $this->assertDatabaseHas('asset_snapshots', ['cash_amount' => 1000000]);
    }

    public function test_編集で別の月へ移すとき、そこに既存があれば止める(): void
    {
        // snapshot_date は unique。素通りすると SQLSTATE[23000] の 500 になり入力も消える
        $july = AssetSnapshot::factory()->create(['snapshot_date' => '2026-07-01', 'cash_amount' => 1000000]);
        AssetSnapshot::factory()->create(['snapshot_date' => '2026-08-01', 'cash_amount' => 1200000]);

        $response = $this->from(route('assets.edit', $july))->put(route('assets.update', $july), [
            'snapshot_date' => '2026-08',
            'cash_amount' => 1000000,
            'nisa_amount' => 0,
            'ideco_amount' => 0,
            'other_investment_amount' => 0,
        ]);

        $response->assertSessionHasErrors('snapshot_date');
        $this->assertSame('2026-07-01', $july->fresh()->snapshot_date->toDateString());
        $this->assertSame(2, AssetSnapshot::count());
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
