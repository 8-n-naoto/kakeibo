@extends('layouts.app')

@section('title', $summary['year'].'年の家計まとめ | 家計簿アプリ')

@section('content')
    @php
        $year = $summary['year'];
        $diffIncome = $summary['income'] - $summary['previous']['income'];
        $diffExpense = $summary['expense'] - $summary['previous']['expense'];
        $signed = fn (int $value) => ($value >= 0 ? '+' : '-').'¥'.number_format(abs($value));
    @endphp

    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <h1 class="text-xl font-bold">{{ $year }}年の家計まとめ</h1>
        <div class="flex gap-2 items-center">
            <form method="GET" action="{{ route('reports.annual') }}">
                <select name="year" onchange="this.form.submit()"
                        class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
                    @foreach ($availableYears as $option)
                        <option value="{{ $option }}" @selected($option === $year)>{{ $option }}年</option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('transactions.export', ['date_from' => $year.'-01-01', 'date_to' => $year.'-12-31']) }}"
               class="bg-white border border-slate-300 text-slate-700 text-sm px-4 py-1.5 rounded-lg hover:bg-slate-100">
                この年の取引をCSV出力
            </a>
        </div>
    </div>

    @if ($summary['transaction_count'] === 0)
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center text-sm text-slate-500">
            {{ $year }}年の取引はまだありません。
        </div>
    @else
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-sm text-slate-500">年間収入</div>
                <div class="text-2xl font-bold text-emerald-600">¥{{ number_format($summary['income']) }}</div>
                <div class="text-xs text-slate-400">前年比 {{ $signed($diffIncome) }}</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-sm text-slate-500">年間支出</div>
                <div class="text-2xl font-bold text-rose-600">¥{{ number_format($summary['expense']) }}</div>
                <div class="text-xs text-slate-400">前年比 {{ $signed($diffExpense) }}</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-sm text-slate-500">年間収支</div>
                <div class="text-2xl font-bold {{ $summary['balance'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                    {{ $signed($summary['balance']) }}
                </div>
                <div class="text-xs text-slate-400">{{ number_format($summary['transaction_count']) }} 件の取引</div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-sm text-slate-500">月あたり平均支出</div>
                <div class="text-2xl font-bold">¥{{ number_format($summary['monthly_average']['expense']) }}</div>
                <div class="text-xs text-slate-400">記録のある {{ $summary['active_months'] }} ヶ月で計算</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-sm text-slate-500 mb-1">固定費 / 変動費</div>
                @if (is_null($summary['fixed_variable']['fixed_rate']))
                    <div class="text-2xl font-bold text-slate-300">—</div>
                @else
                    <div class="text-2xl font-bold">固定費 {{ $summary['fixed_variable']['fixed_rate'] }}%</div>
                    <div class="text-xs text-slate-400">
                        固定 ¥{{ number_format($summary['fixed_variable']['fixed']) }}
                        / 変動 ¥{{ number_format($summary['fixed_variable']['variable']) }}
                    </div>
                @endif
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-sm text-slate-500 mb-1">年間エンゲル係数</div>
                @if (is_null($summary['engel_coefficient']))
                    <div class="text-2xl font-bold text-slate-300">未設定</div>
                    <div class="text-xs text-slate-400">
                        <a href="{{ route('categories.index') }}" class="underline hover:text-slate-600">カテゴリ管理</a>で対象を選んでください
                    </div>
                @else
                    <div class="text-2xl font-bold {{ $summary['engel_coefficient'] > 30 ? 'text-amber-600' : 'text-emerald-600' }}">
                        {{ $summary['engel_coefficient'] }}%
                    </div>
                    <div class="text-xs text-slate-400">目安 25〜30%</div>
                @endif
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
                <div class="text-sm text-slate-500 mb-1">総資産の増減</div>
                @if (is_null($summary['assets']['change']))
                    <div class="text-2xl font-bold text-slate-300">—</div>
                    <div class="text-xs text-slate-400">この年のスナップショットが2件以上必要です</div>
                @else
                    <div class="text-2xl font-bold {{ $summary['assets']['change'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                        {{ $signed($summary['assets']['change']) }}
                    </div>
                    <div class="text-xs text-slate-400">
                        {{ $summary['assets']['first']->snapshot_date->format('n/j') }} ¥{{ number_format($summary['assets']['first']->total_amount) }}
                        → {{ $summary['assets']['last']->snapshot_date->format('n/j') }} ¥{{ number_format($summary['assets']['last']->total_amount) }}
                    </div>
                @endif
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-8">
            <div class="font-semibold mb-3">月別の収支</div>
            <canvas id="annualChart" height="220"></canvas>
            <div class="mt-3 text-xs text-slate-500 flex flex-wrap gap-4">
                <span>いちばん貯まった月: <span class="font-semibold">{{ $summary['best_month']['label'] }}（{{ $signed($summary['best_month']['balance']) }}）</span></span>
                <span>いちばん厳しかった月: <span class="font-semibold">{{ $summary['worst_month']['label'] }}（{{ $signed($summary['worst_month']['balance']) }}）</span></span>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-8">
            <div class="px-4 py-3 border-b border-slate-200 font-semibold">カテゴリ別の支出（前年比つき）</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-50">
                    <tr class="text-left text-slate-500 border-b border-slate-200">
                        <th class="py-2 px-3">カテゴリ</th>
                        <th class="px-3 text-right">年間</th>
                        <th class="px-3 text-right">月平均</th>
                        <th class="px-3 text-right">構成比</th>
                        <th class="px-3 text-right">前年</th>
                        <th class="px-3 text-right">増減</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summary['by_category'] as $row)
                        <tr class="border-b border-slate-100">
                            <td class="py-2 px-3">
                                <span class="inline-block w-2 h-2 rounded-full mr-1" style="background-color: {{ $row['color'] }}"></span>
                                {{ $row['name'] }}
                            </td>
                            <td class="px-3 text-right font-medium">¥{{ number_format($row['amount']) }}</td>
                            <td class="px-3 text-right text-slate-500">¥{{ number_format($row['monthly_average']) }}</td>
                            <td class="px-3 text-right text-slate-500">{{ $row['share'] }}%</td>
                            <td class="px-3 text-right text-slate-400">¥{{ number_format($row['previous']) }}</td>
                            <td class="px-3 text-right {{ $row['diff'] > 0 ? 'text-rose-600' : ($row['diff'] < 0 ? 'text-emerald-600' : 'text-slate-400') }}">
                                {{ $signed($row['diff']) }}
                                @if (! is_null($row['rate']))
                                    <span class="text-xs text-slate-400">（{{ $row['rate'] > 0 ? '+' : '' }}{{ $row['rate'] }}%）</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-slate-400">支出の記録がありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <script>
            new Chart(document.getElementById('annualChart'), {
                type: 'bar',
                data: {
                    labels: @json(collect($summary['months'])->pluck('label')),
                    datasets: [
                        {
                            label: '収入',
                            data: @json(collect($summary['months'])->pluck('income')),
                            backgroundColor: '#34d399',
                        },
                        {
                            label: '支出',
                            data: @json(collect($summary['months'])->pluck('expense')),
                            backgroundColor: '#fb7185',
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { callback: function (value) { return '¥' + value.toLocaleString('ja-JP'); } },
                        },
                    },
                },
            });
        </script>
    @endif
@endsection
