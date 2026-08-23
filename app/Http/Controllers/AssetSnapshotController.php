<?php

namespace App\Http\Controllers;

use App\Models\AssetSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AssetSnapshotController extends Controller
{
    public function index()
    {
        $snapshots = AssetSnapshot::orderByDesc('snapshot_date')->paginate(24);

        return view('assets.index', compact('snapshots'));
    }

    public function create()
    {
        $latest = AssetSnapshot::orderByDesc('snapshot_date')->first();

        return view('assets.create', compact('latest'));
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);
        $validated['snapshot_date'] = Carbon::parse($validated['snapshot_date'])->startOfMonth()->toDateString();

        AssetSnapshot::updateOrCreate(
            ['snapshot_date' => $validated['snapshot_date']],
            $validated
        );

        return redirect()->route('assets.index')->with('status', '資産スナップショットを登録しました。');
    }

    public function edit(AssetSnapshot $asset)
    {
        return view('assets.edit', ['snapshot' => $asset]);
    }

    public function update(Request $request, AssetSnapshot $asset)
    {
        $validated = $this->validated($request);
        $validated['snapshot_date'] = Carbon::parse($validated['snapshot_date'])->startOfMonth()->toDateString();

        $asset->update($validated);

        return redirect()->route('assets.index')->with('status', '資産スナップショットを更新しました。');
    }

    public function destroy(AssetSnapshot $asset)
    {
        $asset->delete();

        return redirect()->route('assets.index')->with('status', '資産スナップショットを削除しました。');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'snapshot_date' => ['required', 'date'],
            'cash_amount' => ['required', 'integer', 'min:0'],
            'nisa_amount' => ['required', 'integer', 'min:0'],
            'ideco_amount' => ['required', 'integer', 'min:0'],
            'other_investment_amount' => ['required', 'integer', 'min:0'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
