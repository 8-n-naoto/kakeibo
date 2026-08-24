@extends('layouts.app')

@section('title', 'ダッシュボード | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold">{{ $currentMonth->format('Y年n月') }}の収支</h1>
        <form method="GET" class="flex items-center gap-2">
            <input type="month" name="month" value="{{ $currentMonth->format('Y-m') }}"
                   onchange="this.form.submit()"
                   class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
        </form>
    </div>

    @if ($pendingRecurrings->isNotEmpty())
        <div class="mb-6 rounded-lg border border-sky-200 bg-sky-50 text-sky-800 px-4 py-3 text-sm">
            📌 {{ $currentMonth->format('Y年n月') }}に未計上の定期支出が
            <span class="font-semibold">{{ $pendingRecurrings->count() }}</span> 件あります
            （{{ $pendingRecurrings->take(3)->pluck('name')->implode('・') }}{{ $pendingRecurrings->count() > 3 ? ' ほか' : '' }}／
            計 ¥{{ number_format((int) $pendingRecurrings->sum('amount')) }}）。
            <a href="{{ route('recurring.index', ['month' => $currentMonth->format('Y-m')]) }}"
               class="underline font-semibold hover:text-sky-900">まとめて計上する</a>
        </div>
    @endif

    @if ($budgetAlerts->isNotEmpty())
        @php
            $alertClasses = [
                'warning' => 'bg-amber-50 border-amber-200 text-amber-800',
                'over' => 'bg-rose-50 border-rose-200 text-rose-800',
            ];
        @endphp
        <div class="mb-6 space-y-2">
            @foreach ($budgetAlerts as $alert)
                <div class="rounded-lg border px-4 py-2 text-sm {{ $alertClasses[$alert['status']] ?? '' }}">
                    {{ $alert['status'] === 'over' ? '⚠️' : '🔔' }}
                    <span class="font-semibold">{{ $alert['name'] }}</span>：
                    予算 ¥{{ number_format($alert['budget']) }} に対して ¥{{ number_format($alert['spent']) }}
                    （{{ $alert['usage_rate'] }}% / {{ $statusLabels[$alert['status']] }}）
                    <a href="{{ route('budgets.index', ['month' => $currentMonth->format('Y-m')]) }}" class="underline ml-1">予算を見る</a>
                </div>
            @endforeach
        </div>
    @endif

    @if ($anomalies->isNotEmpty())
        <div class="mb-6 rounded-lg border border-violet-200 bg-violet-50 px-4 py-3 text-sm text-violet-900">
            <div class="font-semibold mb-1">🔎 今月の気になる支出</div>
            <ul class="space-y-1">
                @foreach ($anomalies->take(5) as $anomaly)
                    <li>
                        @if ($anomaly['type'] === \App\Services\SpendingAnomalyService::TYPE_CATEGORY_SPIKE)
                            <span class="font-semibold">{{ $anomaly['name'] }}</span> が
                            ¥{{ number_format($anomaly['amount']) }}
                            （直近{{ $anomaly['history_months'] }}ヶ月の平均 ¥{{ number_format($anomaly['average']) }} の
                            {{ $anomaly['ratio'] }}倍 / +¥{{ number_format($anomaly['difference']) }}）
                            <a href="{{ route('transactions.index', array_filter(['month' => $currentMonth->format('Y-m'), 'category_id' => $anomaly['category_id']])) }}"
                               class="underline hover:text-violet-950">明細を見る</a>
                        @else
                            <span class="font-semibold">{{ $anomaly['transaction']->shop_name ?: $anomaly['name'] }}</span> で
                            ¥{{ number_format($anomaly['amount']) }}
                            （{{ $anomaly['name'] }}の平均 ¥{{ number_format($anomaly['average']) }} の {{ $anomaly['ratio'] }}倍）
                            <a href="{{ route('transactions.edit', $anomaly['transaction']) }}"
                               class="underline hover:text-violet-950">確認する</a>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($anomalies->count() > 5)
                <div class="text-xs text-violet-700 mt-1">ほか {{ $anomalies->count() - 5 }} 件</div>
            @endif
        </div>
    @endif

    @php
        $tabs = [
            'month' => '今月',
            'assets' => '資産',
            'review' => '振り返り',
        ];
    @endphp

    <div class="border-b border-slate-200 mb-6">
        <div class="flex gap-1 -mb-px overflow-x-auto" role="tablist" aria-label="ダッシュボードの表示切替">
            @foreach ($tabs as $key => $label)
                <button type="button" data-tab="{{ $key }}"
                        role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}"
                        aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}"
                        class="dashboard-tab whitespace-nowrap px-4 py-2 text-sm border-b-2 border-transparent text-slate-500 hover:text-slate-800">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div data-tab-panel="month" id="panel-month" role="tabpanel" aria-labelledby="tab-month">
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-sm text-slate-500">収入</div>
            <div class="text-2xl font-bold text-emerald-600">¥{{ number_format($income) }}</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-sm text-slate-500">支出</div>
            <div class="text-2xl font-bold text-rose-600">¥{{ number_format($expense) }}</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-sm text-slate-500">収支</div>
            <div class="text-2xl font-bold {{ $balance >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                ¥{{ number_format($balance) }}
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="text-sm text-slate-500">エンゲル係数(食料費/支出)</div>
            @if (! $foodCategoriesConfigured)
                <div class="text-2xl font-bold text-slate-300">未設定</div>
                <div class="text-xs text-slate-400">
                    <a href="{{ route('categories.index') }}" class="underline hover:text-slate-600">カテゴリ管理</a>
                    で対象カテゴリを選んでください
                </div>
            @elseif (is_null($engelCoefficient))
                <div class="text-2xl font-bold text-slate-300">—</div>
                <div class="text-xs text-slate-400">目安 25〜30%</div>
            @else
                <div class="text-2xl font-bold {{ $engelCoefficient > 30 ? 'text-amber-600' : 'text-emerald-600' }}">
                    {{ $engelCoefficient }}%
                </div>
                <div class="text-xs text-slate-400">食料費 ¥{{ number_format($foodExpense) }} / 目安 25〜30%</div>
            @endif
        </div>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <h2 class="font-semibold mb-3">月別収支の推移(直近12ヶ月)</h2>
            <canvas id="trendChart" height="220"></canvas>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <h2 class="font-semibold mb-3">カテゴリ別支出(今月)</h2>
            @if ($byCategory->isEmpty())
                <p class="text-sm text-slate-400">この月の支出データはまだありません。</p>
            @else
                <canvas id="categoryChart" height="220"></canvas>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold">最近の取引</h2>
            <a href="{{ route('transactions.index') }}" class="text-sm text-emerald-600 hover:underline">すべて見る →</a>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500 border-b border-slate-200">
                    <th class="py-2">日付</th>
                    <th>カテゴリ</th>
                    <th>店名/メモ</th>
                    <th class="text-right">金額</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions as $t)
                    <tr class="border-b border-slate-100">
                        <td class="py-2">{{ $t->transaction_date->format('n/j') }}</td>
                        <td>
                            <span class="inline-block w-2 h-2 rounded-full mr-1" style="background-color: {{ $t->category?->color ?? '#94a3b8' }}"></span>
                            {{ $t->category?->name ?? '未分類' }}
                        </td>
                        <td class="text-slate-500">{{ $t->shop_name ?? $t->memo }}</td>
                        <td class="text-right font-medium {{ $t->type === 'income' ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $t->type === 'income' ? '+' : '-' }}¥{{ number_format($t->amount) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-4 text-center text-slate-400">まだ取引がありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    </div>

    <div data-tab-panel="assets" id="panel-assets" role="tabpanel" aria-labelledby="tab-assets" hidden>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold">総資産の推移(直近12ヶ月)</h2>
                <a href="{{ route('assets.index') }}" class="text-sm text-emerald-600 hover:underline">詳細 →</a>
            </div>
            @if ($netWorthTrend->isEmpty())
                <p class="text-sm text-slate-400">資産スナップショットがまだありません。<a href="{{ route('assets.create') }}" class="text-emerald-600 hover:underline">今月分を登録する</a></p>
            @else
                <canvas id="netWorthChart" height="200"></canvas>
                @if (! is_null($netWorthChange))
                    <div class="mt-2 text-xs {{ $netWorthChange >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                        前回スナップショット比: {{ $netWorthChange >= 0 ? '+' : '' }}¥{{ number_format($netWorthChange) }}
                    </div>
                @endif
            @endif
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold">貯蓄目標</h2>
                <a href="{{ route('savings-goals.index') }}" class="text-sm text-emerald-600 hover:underline">詳細 →</a>
            </div>
            @if ($savingsGoals->isEmpty())
                <p class="text-sm text-slate-400">貯蓄目標がまだありません。<a href="{{ route('savings-goals.create') }}" class="text-emerald-600 hover:underline">目標を追加する</a></p>
            @else
                <div class="space-y-4">
                    @foreach ($savingsGoals as $goal)
                        @php $rate = min(100, $goal->progress_rate); @endphp
                        <div>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span>{{ $goal->name }}</span>
                                <span class="text-slate-500">{{ $goal->progress_rate }}%</span>
                            </div>
                            <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full bg-emerald-600" style="width: {{ $rate }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    @if ($investmentAccounts->isNotEmpty())
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-8">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold">NISA/iDeCo({{ now()->year }}年度)</h2>
                <a href="{{ route('investment-accounts.index') }}" class="text-sm text-emerald-600 hover:underline">詳細 →</a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                @foreach ($investmentAccounts as $account)
                    <div>
                        <div class="text-sm text-slate-500">{{ $account->account_type_label }}</div>
                        <div class="text-lg font-bold">¥{{ number_format($account->evaluation_amount) }}</div>
                        <div class="text-xs text-slate-400">枠消化 {{ $account->usage_rate }}%</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
    </div>

    <div data-tab-panel="review" id="panel-review" role="tabpanel" aria-labelledby="tab-review" hidden>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-semibold">固定費 / 変動費</h2>
                <a href="{{ route('categories.index') }}" class="text-sm text-emerald-600 hover:underline">分類を編集 →</a>
            </div>
            @if (is_null($fixedVariable['fixed_rate']))
                <p class="text-sm text-slate-400">この月の支出データはまだありません。</p>
            @else
                <div class="flex items-end gap-6 mb-3">
                    <div>
                        <div class="text-xs text-slate-500">固定費</div>
                        <div class="text-xl font-bold text-sky-700">¥{{ number_format($fixedVariable['fixed']) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-500">変動費</div>
                        <div class="text-xl font-bold text-slate-700">¥{{ number_format($fixedVariable['variable']) }}</div>
                    </div>
                    <div class="ml-auto text-right">
                        <div class="text-xs text-slate-500">固定費率</div>
                        <div class="text-xl font-bold {{ $fixedVariable['fixed_rate'] > 50 ? 'text-amber-600' : 'text-emerald-600' }}">
                            {{ $fixedVariable['fixed_rate'] }}%
                        </div>
                    </div>
                </div>
                <div class="w-full h-3 bg-slate-100 rounded-full overflow-hidden flex">
                    <div class="h-full bg-sky-500" style="width: {{ $fixedVariable['fixed_rate'] }}%"></div>
                    <div class="h-full bg-slate-400" style="width: {{ 100 - $fixedVariable['fixed_rate'] }}%"></div>
                </div>
                @if ($fixedVariable['fixed_categories']->isNotEmpty())
                    <ul class="mt-3 text-sm text-slate-600 space-y-1">
                        @foreach ($fixedVariable['fixed_categories']->take(5) as $name => $amount)
                            <li class="flex justify-between">
                                <span>{{ $name }}</span>
                                <span>¥{{ number_format($amount) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <h2 class="font-semibold mb-3">前年同月比（{{ $yearOverYear['previous_month']->format('Y年n月') }} との比較）</h2>
            <div class="grid grid-cols-2 gap-4 mb-3">
                @foreach ([['収入', $yearOverYear['income'], 'text-emerald-600'], ['支出', $yearOverYear['expense'], 'text-rose-600']] as [$label, $data, $tone])
                    <div>
                        <div class="text-xs text-slate-500">{{ $label }}</div>
                        <div class="text-lg font-bold {{ $tone }}">¥{{ number_format($data['current']) }}</div>
                        <div class="text-xs text-slate-500">
                            前年 ¥{{ number_format($data['previous']) }}
                            <span class="{{ $data['diff'] >= 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                （{{ $data['diff'] >= 0 ? '+' : '−' }}¥{{ number_format(abs($data['diff'])) }}@if (! is_null($data['rate'])) / {{ $data['rate'] > 0 ? '+' : '' }}{{ $data['rate'] }}%@endif）
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
            @if ($yearOverYear['categories']->isEmpty())
                <p class="text-sm text-slate-400">比較できる支出データがありません。</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b border-slate-200">
                            <th class="py-1">カテゴリ</th>
                            <th class="text-right">今年</th>
                            <th class="text-right">前年</th>
                            <th class="text-right">差額</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($yearOverYear['categories']->take(5) as $row)
                            <tr class="border-b border-slate-100">
                                <td class="py-1">{{ $row['name'] }}</td>
                                <td class="text-right">¥{{ number_format($row['current']) }}</td>
                                <td class="text-right text-slate-500">¥{{ number_format($row['previous']) }}</td>
                                <td class="text-right {{ $row['diff'] > 0 ? 'text-rose-600' : ($row['diff'] < 0 ? 'text-emerald-600' : 'text-slate-400') }}">
                                    {{ $row['diff'] > 0 ? '+' : ($row['diff'] < 0 ? '−' : '') }}¥{{ number_format(abs($row['diff'])) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
    </div>

    <script>
        const trend = @json($trend);
        new Chart(document.getElementById('trendChart'), {
            type: 'bar',
            data: {
                labels: trend.map(t => t.label),
                datasets: [
                    { label: '収入', data: trend.map(t => t.income), backgroundColor: '#10b981' },
                    { label: '支出', data: trend.map(t => t.expense), backgroundColor: '#f43f5e' },
                ]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });

        @if ($byCategory->isNotEmpty())
        const byCategory = @json($byCategory);
        new Chart(document.getElementById('categoryChart'), {
            type: 'doughnut',
            data: {
                labels: byCategory.map(c => c.name),
                datasets: [{
                    data: byCategory.map(c => c.amount),
                    backgroundColor: byCategory.map(c => c.color),
                }]
            },
            options: { responsive: true }
        });
        @endif

        @if ($netWorthTrend->isNotEmpty())
        const netWorthTrend = @json($netWorthTrend);
        new Chart(document.getElementById('netWorthChart'), {
            type: 'line',
            data: {
                labels: netWorthTrend.map(t => t.label),
                datasets: [{
                    label: '総資産',
                    data: netWorthTrend.map(t => t.total),
                    borderColor: '#0d9488',
                    backgroundColor: 'rgba(13,148,136,0.12)',
                    fill: true,
                    tension: 0.25,
                }]
            },
            options: { responsive: true, scales: { y: { beginAtZero: false } } }
        });
        @endif
    </script>

    <script>
        // ダッシュボードのタブ切替。
        // データはすべて最初のリクエストで受け取っているので、表示の出し分けだけを行う。
        // 非表示のうちに描画された Chart.js は幅0のままになるので、表示時に resize() する。
        (function () {
            var STORAGE_KEY = 'kakeibo.dashboard.tab';
            var buttons = document.querySelectorAll('.dashboard-tab');
            var panels = document.querySelectorAll('[data-tab-panel]');
            var activeClasses = ['border-emerald-600', 'text-slate-900', 'font-semibold'];

            function apply(name) {
                buttons.forEach(function (button) {
                    var on = button.dataset.tab === name;
                    activeClasses.forEach(function (cls) { button.classList.toggle(cls, on); });
                    button.classList.toggle('text-slate-500', !on);
                    button.setAttribute('aria-selected', on ? 'true' : 'false');
                    button.setAttribute('tabindex', on ? '0' : '-1');
                });

                panels.forEach(function (panel) {
                    panel.hidden = panel.dataset.tabPanel !== name;
                });

                // Chart.js v4 に Chart.instances は無いので canvas から引く
                if (window.Chart && typeof Chart.getChart === 'function') {
                    document.querySelectorAll('canvas').forEach(function (canvas) {
                        var chart = Chart.getChart(canvas);
                        if (chart) {
                            try { chart.resize(); } catch (e) { /* 描画前は無視する */ }
                        }
                    });
                }
            }

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    apply(button.dataset.tab);
                    try { localStorage.setItem(STORAGE_KEY, button.dataset.tab); } catch (e) { /* 保存できなくても動く */ }
                });
            });

            var saved = null;
            try { saved = localStorage.getItem(STORAGE_KEY); } catch (e) { saved = null; }

            var known = Array.prototype.map.call(buttons, function (b) { return b.dataset.tab; });
            apply(known.indexOf(saved) >= 0 ? saved : 'month');
        })();
    </script>

@endsection
