@extends('layouts.app')

@section('title', '資産推移 | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold">資産推移(スナップショット)</h1>
        <a href="{{ route('assets.create') }}" class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">+ 今月分を登録</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-6">
        <h2 class="font-semibold mb-3">総資産の推移</h2>
        @if ($snapshots->isEmpty())
            <p class="text-sm text-slate-400">まだスナップショットがありません。まずは今月分を登録してください。</p>
        @else
            <canvas id="netWorthChart" height="220"></canvas>
        @endif
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50">
                <tr class="text-left text-slate-500 border-b border-slate-200">
                    <th class="py-2 px-3">対象月</th>
                    <th class="px-3 text-right">現金・預金</th>
                    <th class="px-3 text-right">NISA</th>
                    <th class="px-3 text-right">iDeCo</th>
                    <th class="px-3 text-right">その他投資</th>
                    <th class="px-3 text-right">総資産</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($snapshots as $s)
                    <tr class="border-b border-slate-100">
                        <td class="py-2 px-3">{{ $s->snapshot_date->format('Y年n月') }}</td>
                        <td class="px-3 text-right">¥{{ number_format($s->cash_amount) }}</td>
                        <td class="px-3 text-right">¥{{ number_format($s->nisa_amount) }}</td>
                        <td class="px-3 text-right">¥{{ number_format($s->ideco_amount) }}</td>
                        <td class="px-3 text-right">¥{{ number_format($s->other_investment_amount) }}</td>
                        <td class="px-3 text-right font-semibold text-emerald-700">¥{{ number_format($s->total_amount) }}</td>
                        <td class="px-3 text-right whitespace-nowrap">
                            <a href="{{ route('assets.edit', $s) }}" class="text-slate-500 hover:text-emerald-600 mr-2">編集</a>
                            <form action="{{ route('assets.destroy', $s) }}" method="POST" class="inline" onsubmit="return confirm('削除しますか?');">
                                @csrf @method('DELETE')
                                <button class="text-slate-500 hover:text-rose-600">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-6 text-center text-slate-400">スナップショットがありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $snapshots->links() }}</div>

    @if ($snapshots->isNotEmpty())
        <script>
            const snapshots = @json($snapshots->sortBy('snapshot_date')->values());
            new Chart(document.getElementById('netWorthChart'), {
                type: 'line',
                data: {
                    labels: snapshots.map(s => s.snapshot_date),
                    datasets: [{
                        label: '総資産',
                        data: snapshots.map(s => s.cash_amount + s.nisa_amount + s.ideco_amount + s.other_investment_amount),
                        borderColor: '#0d9488',
                        backgroundColor: 'rgba(13,148,136,0.12)',
                        fill: true,
                        tension: 0.25,
                    }]
                },
                options: { responsive: true, scales: { y: { beginAtZero: false } } }
            });
        </script>
    @endif
@endsection
