@extends('layouts.app')

@section('title', '予算の提案 | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold">過去の実績から予算を提案</h1>
            <p class="text-sm text-slate-500 mt-1">
                {{ $month->format('Y年n月') }}の予算を、直近{{ $lookback }}ヶ月の実績から見積もります。
            </p>
        </div>
        <a href="{{ route('budgets.index', ['month' => $month->format('Y-m')]) }}"
           class="text-sm text-slate-500 hover:text-slate-700">予算管理に戻る</a>
    </div>

    <form method="GET" class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-6 flex flex-wrap items-end gap-4">
        <label class="text-sm">
            <span class="block text-slate-500 mb-1">対象月</span>
            <input type="month" name="month" value="{{ $month->format('Y-m') }}"
                   class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
        </label>
        <label class="text-sm">
            <span class="block text-slate-500 mb-1">さかのぼる月数</span>
            <select name="lookback" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
                @foreach ($lookbackOptions as $option)
                    <option value="{{ $option }}" @selected($lookback === $option)>{{ $option }}ヶ月</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="bg-slate-700 text-white text-sm px-4 py-2 rounded-lg hover:bg-slate-800">
            再計算
        </button>
    </form>

    @if (! $suggestion['enough'])
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 text-sm text-slate-600">
            <p class="font-semibold text-slate-800 mb-2">まだ提案できません。</p>
            <p>
                直近{{ $lookback }}ヶ月のうち、支出が記録されている月は{{ $suggestion['available'] }}ヶ月です。
                {{ $minMonths }}ヶ月分の実績がないと、当てずっぽうと変わらない数字になってしまうため出していません。
            </p>
            <p class="mt-2">
                しばらく記録を続けるか、
                <a href="{{ route('imports.create') }}" class="text-emerald-700 underline">カード明細のCSV取込</a>
                で過去分を入れると提案できるようになります。
            </p>
        </div>
    @else
        <form method="POST" action="{{ route('budgets.suggestions.apply') }}" id="apply-form">
            @csrf
            <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-6">
                <div class="flex flex-wrap items-center gap-6 text-sm">
                    <span class="text-slate-500">
                        参照した月：{{ implode('、', array_map(fn ($m) => \Illuminate\Support\Carbon::createFromFormat('!Y-m', $m)->format('Y年n月'), $suggestion['months'])) }}
                    </span>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-6 text-sm">
                    <span class="font-medium text-slate-700">登録先</span>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="scope" value="default" checked>
                        <span>毎月（デフォルト予算）</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="scope" value="month">
                        <span>{{ $month->format('Y年n月') }}だけ</span>
                    </label>
                </div>
                @if ($suggestion['uncategorized'] > 0)
                    <p class="mt-3 text-xs text-amber-700">
                        参照した期間にカテゴリ未設定の支出が合計 ¥{{ number_format($suggestion['uncategorized']) }}（月あたり約 ¥{{ number_format((int) round($suggestion['uncategorized'] / max(1, $suggestion['available']))) }}）あります。
                        カテゴリ別の提案には含まれていません（支出全体には含まれます）。カテゴリを付けると提案の精度が上がります。
                    </p>
                @endif
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500 text-xs">
                            <tr>
                                <th class="px-3 py-2 text-left w-10">
                                    <input type="checkbox" id="check-all" aria-label="すべて選択">
                                </th>
                                <th class="px-3 py-2 text-left">カテゴリ</th>
                                <th class="px-3 py-2 text-right">最小</th>
                                <th class="px-3 py-2 text-right">中央値</th>
                                <th class="px-3 py-2 text-right">最大</th>
                                <th class="px-3 py-2 text-right">いまの予算</th>
                                <th class="px-3 py-2 text-right">提案</th>
                                <th class="px-3 py-2 text-left">根拠</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach (collect($suggestion['overall'] ? [$suggestion['overall']] : [])->concat($suggestion['categories']) as $row)
                                <tr class="{{ $row['key'] === 'overall' ? 'bg-slate-50/60 font-medium' : '' }}">
                                    <td class="px-3 py-2">
                                        <input type="checkbox" name="selected[]" value="{{ $row['key'] }}"
                                               class="suggestion-check"
                                               @checked(in_array($row['key'], (array) old('selected', []), true))
                                               aria-label="{{ $row['name'] }}の予算を登録する">
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ $row['name'] }}
                                        @if ($row['fixed'])
                                            <span class="ml-1 text-xs text-slate-400">固定費</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right text-slate-500">¥{{ number_format($row['min']) }}</td>
                                    <td class="px-3 py-2 text-right">¥{{ number_format($row['median']) }}</td>
                                    <td class="px-3 py-2 text-right text-slate-500">¥{{ number_format($row['max']) }}</td>
                                    <td class="px-3 py-2 text-right">
                                        @if ($row['current'] === null)
                                            <span class="text-slate-400">未設定</span>
                                        @else
                                            ¥{{ number_format($row['current']) }}
                                            @if ($row['diff'] !== 0)
                                                <span class="block text-xs {{ $row['diff'] > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                                    {{ $row['diff'] > 0 ? '+' : '−' }}¥{{ number_format(abs($row['diff'])) }}
                                                </span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <input type="number" name="amounts[{{ $row['key'] }}]"
                                               value="{{ old('amounts.'.$row['key'], $row['suggested']) }}"
                                               min="0" step="10"
                                               class="w-28 border border-slate-300 rounded-lg px-2 py-1 text-right"
                                               aria-label="{{ $row['name'] }}の予算額">
                                    </td>
                                    <td class="px-3 py-2 text-xs text-slate-500">{{ $row['basis'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-100 px-4 py-3 flex items-center justify-between">
                    <p class="text-xs text-slate-500">
                        変動費は中央値、固定費は直近の実績をもとにしています。数字は直してから登録できます。
                    </p>
                    <button type="submit"
                            class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">
                        選んだ予算を登録する
                    </button>
                </div>
            </div>
        </form>

        <script>
            (function () {
                var all = document.getElementById('check-all');
                if (!all) return;
                var boxes = document.querySelectorAll('.suggestion-check');
                all.addEventListener('change', function () {
                    boxes.forEach(function (box) { box.checked = all.checked; });
                });
            })();
        </script>
    @endif
@endsection
