@extends('layouts.app')

@section('title', '予算管理 | 家計簿アプリ')

@php
    $statusClasses = [
        'ok' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'warning' => 'bg-amber-50 text-amber-700 border-amber-200',
        'over' => 'bg-rose-50 text-rose-700 border-rose-200',
    ];
    $barClasses = ['ok' => 'bg-emerald-500', 'warning' => 'bg-amber-500', 'over' => 'bg-rose-500'];
@endphp

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold">予算管理</h1>
        <div class="flex items-center gap-3">
            <form method="GET">
                <input type="month" name="month" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()"
                       class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
            </form>
            <a href="{{ route('budgets.suggestions', ['month' => $month->format('Y-m')]) }}"
               class="border border-slate-300 text-slate-700 text-sm px-4 py-2 rounded-lg hover:bg-slate-50">実績から提案</a>
            <a href="{{ route('budgets.create', ['month' => $month->format('Y-m')]) }}"
               class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">+ 予算を追加</a>
        </div>
    </div>

    @if ($summary['alerts']->isNotEmpty())
        <div class="mb-6 space-y-2">
            @foreach ($summary['alerts'] as $alert)
                <div class="rounded-lg border px-4 py-2 text-sm {{ $statusClasses[$alert['status']] }}">
                    <span class="font-semibold">{{ $alert['name'] }}</span>：
                    予算 ¥{{ number_format($alert['budget']) }} に対して ¥{{ number_format($alert['spent']) }}（{{ $alert['usage_rate'] }}%）
                    — {{ $statusLabels[$alert['status']] }}
                    @if ($alert['remaining'] < 0)
                        <span class="font-semibold">¥{{ number_format(abs($alert['remaining'])) }} オーバー</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-8">
        <h2 class="font-semibold mb-4">{{ $month->format('Y年n月') }}の消化状況</h2>

        @if (is_null($summary['overall']) && $summary['categories']->isEmpty())
            <p class="text-sm text-slate-400">この月に適用される予算がまだ設定されていません。</p>
        @else
            <div class="space-y-4">
                @foreach (collect($summary['overall'] ? [$summary['overall']] : [])->concat($summary['categories']) as $row)
                    <div>
                        <div class="flex items-center justify-between text-sm mb-1">
                            <span class="font-medium">{{ $row['name'] }}</span>
                            <span class="text-slate-500">
                                ¥{{ number_format($row['spent']) }} / ¥{{ number_format($row['budget']) }}
                                （{{ $row['usage_rate'] }}%）
                            </span>
                        </div>
                        <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full {{ $barClasses[$row['status']] }}" style="width: {{ min(100, $row['usage_rate']) }}%"></div>
                        </div>
                        <div class="text-xs mt-1 {{ $row['remaining'] < 0 ? 'text-rose-600' : 'text-slate-400' }}">
                            @if ($row['remaining'] >= 0)
                                残り ¥{{ number_format($row['remaining']) }}
                            @else
                                ¥{{ number_format(abs($row['remaining'])) }} 超過
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50">
                <tr class="text-left text-slate-500 border-b border-slate-200">
                    <th class="py-2 px-3">対象</th>
                    <th class="px-3">適用月</th>
                    <th class="px-3 text-right">予算額</th>
                    <th class="px-3">メモ</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($budgets as $budget)
                    <tr class="border-b border-slate-100">
                        <td class="py-2 px-3">{{ $budget->scope_label }}</td>
                        <td class="px-3">{{ $budget->period_label }}</td>
                        <td class="px-3 text-right">¥{{ number_format($budget->amount) }}</td>
                        <td class="px-3 text-slate-500">{{ $budget->memo }}</td>
                        <td class="px-3 text-right whitespace-nowrap">
                            <a href="{{ route('budgets.edit', $budget) }}" class="text-slate-500 hover:text-emerald-600 mr-2">編集</a>
                            <form action="{{ route('budgets.destroy', $budget) }}" method="POST" class="inline" onsubmit="return confirm('削除しますか?');">
                                @csrf @method('DELETE')
                                <button class="text-slate-500 hover:text-rose-600">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-slate-400">予算が登録されていません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
