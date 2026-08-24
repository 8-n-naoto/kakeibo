<?php

namespace App\Http\Controllers;

use App\Models\AssetSnapshot;
use App\Support\DomainLimits;
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

        // 月初に丸めるので、同じ月に2回登録すると黙って置き換わっていた。
        // 資産推移は「その月いくらだったか」の記録なので、上書きは明示的に選ばせる。
        $existing = AssetSnapshot::where('snapshot_date', $validated['snapshot_date'])->first();

        if ($existing !== null && ! $request->boolean('overwrite')) {
            return back(fallback: route('assets.create'))->withInput()->withErrors([
                'snapshot_date' => sprintf(
                    '%s の記録はすでにあります（総額 ¥%s・記録日 %s）。'
                        .'置き換えるなら「すでにある月は上書きする」にチェックを入れてください。',
                    Carbon::parse($validated['snapshot_date'])->format('Y年n月'),
                    number_format($existing->total_amount),
                    $existing->updated_at?->format('Y/n/j') ?? '不明',
                ),
            ]);
        }

        AssetSnapshot::updateOrCreate(
            ['snapshot_date' => $validated['snapshot_date']],
            $validated
        );

        return redirect()->route('assets.index')->with(
            'status',
            $existing !== null
                ? '資産スナップショットを上書きしました。'
                : '資産スナップショットを登録しました。',
        );
    }

    public function edit(AssetSnapshot $asset)
    {
        return view('assets.edit', ['snapshot' => $asset]);
    }

    public function update(Request $request, AssetSnapshot $asset)
    {
        $validated = $this->validated($request);
        $validated['snapshot_date'] = Carbon::parse($validated['snapshot_date'])->startOfMonth()->toDateString();

        // snapshot_date には unique 制約がある。別の月に付け替えたときに、
        // そこへ既に記録があると SQLSTATE[23000] で 500 になり、入力も消える。
        $conflict = AssetSnapshot::where('snapshot_date', $validated['snapshot_date'])
            ->whereKeyNot($asset->getKey())
            ->first();

        if ($conflict !== null) {
            return back(fallback: route('assets.edit', $asset))->withInput()->withErrors([
                'snapshot_date' => sprintf(
                    '%s の記録はすでにあります（総額 ¥%s）。先にそちらを削除するか、別の月を選んでください。',
                    Carbon::parse($validated['snapshot_date'])->format('Y年n月'),
                    number_format($conflict->total_amount),
                ),
            ]);
        }

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
            'snapshot_date' => array_merge(['required'], DomainLimits::dateRules()),
            'cash_amount' => array_merge(['required'], DomainLimits::amountRules()),
            'nisa_amount' => array_merge(['required'], DomainLimits::amountRules()),
            'ideco_amount' => array_merge(['required'], DomainLimits::amountRules()),
            'other_investment_amount' => array_merge(['required'], DomainLimits::amountRules()),
            'memo' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
