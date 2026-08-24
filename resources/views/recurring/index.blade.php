@extends('layouts.app')

@section('title', '定期支出 | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
        <h1 class="text-xl font-bold">定期支出</h1>
        <a href="{{ route('recurring.create') }}"
           class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">+ 定期支出を追加</a>
    </div>
    <p class="text-sm text-slate-500 mb-6">
        家賃・保険・サブスクのようにレシートもカード明細も出ない支出を登録しておくと、月ごとにまとめて計上できます。
    </p>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-2 items-end">
            <div>
                <label class="block text-xs text-slate-500 mb-1">対象月</label>
                <input type="month" name="month" value="{{ $month->format('Y-m') }}"
                       class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
            </div>
            <button class="bg-slate-200 text-slate-700 text-sm px-4 py-1.5 rounded-lg hover:bg-slate-300">表示</button>
        </form>

        <div class="mt-4 pt-4 border-t border-slate-100">
            @if ($pending->isEmpty())
                <p class="text-sm text-slate-500">{{ $month->format('Y年n月') }}の定期支出はすべて計上済みです。</p>
            @else
                <p class="text-sm text-slate-700 mb-2">
                    {{ $month->format('Y年n月') }}に未計上の定期支出が <span class="font-bold">{{ $pending->count() }}</span> 件あります。
                </p>
                <ul class="text-sm text-slate-600 list-disc list-inside space-y-0.5 mb-3">
                    @foreach ($pending as $rule)
                        <li>
                            {{ $rule->scheduledDateFor($month)->format('n/j') }}
                            {{ $rule->name }}
                            / {{ $rule->category?->name ?? '未分類' }}
                            / {{ $rule->type === 'income' ? '+' : '-' }}¥{{ number_format($rule->amount) }}
                        </li>
                    @endforeach
                </ul>
                <form method="POST" action="{{ route('recurring.post') }}"
                      onsubmit="return confirm('{{ $month->format('Y年n月') }}の未計上分を取引として登録します。よろしいですか?');">
                    @csrf
                    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                    <button class="bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">
                        {{ $month->format('Y年n月') }}分をまとめて計上する
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50">
                <tr class="text-left text-slate-500 border-b border-slate-200">
                    <th class="py-2 px-3">名前</th>
                    <th class="px-3">カテゴリ</th>
                    <th class="px-3 text-right">金額</th>
                    <th class="px-3 text-right">計上日</th>
                    <th class="px-3">期間</th>
                    <th class="px-3">状態</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recurrings as $rule)
                    <tr class="border-b border-slate-100 {{ $rule->is_active ? '' : 'text-slate-400' }}">
                        <td class="py-2 px-3">{{ $rule->name }}</td>
                        <td class="px-3">{{ $rule->category?->name ?? '未分類' }}</td>
                        <td class="px-3 text-right font-medium {{ $rule->type === 'income' ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $rule->type === 'income' ? '+' : '-' }}¥{{ number_format($rule->amount) }}
                        </td>
                        <td class="px-3 text-right">毎月{{ $rule->day_of_month }}日</td>
                        @php
                            // 終了日が開始日より前だと appliesTo() が常に false になり、
                            // 黙って計上されなくなる。新規登録では弾くようにしたが、
                            // それ以前に保存されたものはここでしか気づけない
                            // appliesTo() は「月」で比べる。日で比べると、
                            // 同じ月の中で前後しているだけの行に「計上されません」と嘘をつく
                            $periodBroken = $rule->starts_on && $rule->ends_on
                                && $rule->ends_on->copy()->startOfMonth()
                                    ->lt($rule->starts_on->copy()->startOfMonth());
                        @endphp
                        <td class="px-3 text-xs {{ $periodBroken ? 'text-rose-600' : 'text-slate-500' }}">
                            {{ $rule->starts_on?->format('Y/n/j') ?? 'いつから でも' }} 〜 {{ $rule->ends_on?->format('Y/n/j') ?? '無期限' }}
                            @if ($periodBroken)
                                <span class="block font-semibold">終了日が開始日より前です。この設定では計上されません</span>
                            @endif
                        </td>
                        <td class="px-3">
                            @if ($rule->is_active)
                                <span class="text-xs rounded px-2 py-0.5 bg-emerald-50 text-emerald-700">有効</span>
                            @else
                                <span class="text-xs rounded px-2 py-0.5 bg-slate-100 text-slate-500">停止中</span>
                            @endif
                        </td>
                        <td class="px-3 text-right whitespace-nowrap">
                            <a href="{{ route('recurring.edit', $rule) }}" class="text-slate-500 hover:text-emerald-600 mr-2">編集</a>
                            <form action="{{ route('recurring.destroy', $rule) }}" method="POST" class="inline"
                                  onsubmit="return confirm('削除しますか? 計上済みの取引は残ります。');">
                                @csrf @method('DELETE')
                                <button class="text-slate-500 hover:text-rose-600">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-6 text-center text-slate-400">まだ登録がありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
