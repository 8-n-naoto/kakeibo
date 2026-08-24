@extends('layouts.app')

@section('title', 'カテゴリ管理 | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold">カテゴリ管理</h1>
        <a href="{{ route('categories.create') }}" class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">+ カテゴリを追加</a>
    </div>

    {{-- 予算と自動分類ルールは支出カテゴリにしか付かない（どちらも expense で絞ってある）。
         収入カテゴリの表に「予算 0 / ルール 0」と赤い警告を出しても、
         毎回はずれる警告になって、本当に効く側まで読み飛ばされる。 --}}
    @foreach ([['支出カテゴリ', $expenseCategories, true], ['収入カテゴリ', $incomeCategories, false]] as [$heading, $list, $hasCascades])
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
            <div class="px-4 py-3 border-b border-slate-200 font-semibold">{{ $heading }}</div>
            @if ($hasCascades)
                <p class="px-4 pt-3 text-xs text-slate-500">
                    カテゴリを削除すると、取引と定期支出は「未分類」になりますが、
                    <span class="text-rose-600 font-semibold">予算と自動分類ルールは一緒に削除されます（取り消せません）</span>。
                </p>
            @endif
            <table class="w-full text-sm">
                <thead class="bg-slate-50">
                    <tr class="text-left text-slate-500 border-b border-slate-200">
                        <th class="py-2 px-3">カテゴリ</th>
                        <th class="px-3">固定/変動</th>
                        <th class="px-3">食料費</th>
                        <th class="px-3 text-right">取引</th>
                        <th class="px-3 text-right">定期支出</th>
                        @if ($hasCascades)
                            <th class="px-3 text-right">予算</th>
                            <th class="px-3 text-right">ルール</th>
                        @endif
                        <th class="px-3 text-right">表示順</th>
                        <th class="px-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($list as $category)
                        <tr class="border-b border-slate-100">
                            <td class="py-2 px-3">
                                <span class="inline-block w-2 h-2 rounded-full mr-1" style="background-color: {{ $category->color }}"></span>
                                {{ $category->name }}
                            </td>
                            <td class="px-3">
                                @if ($category->type === 'expense')
                                    <span class="text-xs rounded px-2 py-0.5 {{ $category->isFixedCost() ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-600' }}">
                                        {{ $category->expense_nature_label }}
                                    </span>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-3">
                                @if ($category->counts_as_food)
                                    <span class="text-xs rounded px-2 py-0.5 bg-amber-50 text-amber-700">含める</span>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-3 text-right text-slate-500">{{ number_format($category->transactions_count) }}</td>
                            <td class="px-3 text-right text-slate-500">{{ number_format($category->recurring_transactions_count) }}</td>
                            @if ($hasCascades)
                                {{-- 予算と学習ルールは cascadeOnDelete。カテゴリを消すと**一緒に消える** --}}
                                <td class="px-3 text-right {{ $category->budgets_count > 0 ? 'text-rose-600 font-semibold' : 'text-slate-500' }}">
                                    {{ number_format($category->budgets_count) }}
                                </td>
                                <td class="px-3 text-right {{ $category->merchant_category_rules_count > 0 ? 'text-rose-600 font-semibold' : 'text-slate-500' }}">
                                    {{ number_format($category->merchant_category_rules_count) }}
                                    @if ($category->manual_rules_count > 0)
                                        <span class="block text-[10px] text-slate-400">手動 {{ number_format($category->manual_rules_count) }}</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-3 text-right text-slate-500">{{ $category->sort_order }}</td>
                            <td class="px-3 text-right whitespace-nowrap">
                                <a href="{{ route('categories.edit', $category) }}" class="text-slate-500 hover:text-emerald-600 mr-2">編集</a>
                                @php
                                    // キーを手書きしない（コントローラ側とずれると、削除が必ず拒否される）
                                    $impact = \App\Http\Controllers\CategoryController::impactFromCounts($category);
                                    $lines = ['「'.$category->name.'」を削除します。取り消せません。'];
                                    if ($impact['transactions'] > 0) {
                                        $lines[] = '・取引 '.number_format($impact['transactions']).'件 → 未分類になります（消えません）';
                                    }
                                    if ($impact['recurring_transactions'] > 0) {
                                        $lines[] = '・定期支出 '.number_format($impact['recurring_transactions']).'件 → 未分類になります（消えません）';
                                    }
                                    if ($impact['budgets'] > 0) {
                                        $lines[] = '・予算 '.number_format($impact['budgets']).'件 → 一緒に削除されます';
                                    }
                                    if ($impact['merchant_category_rules'] > 0) {
                                        $lines[] = '・自動分類ルール '.number_format($impact['merchant_category_rules']).'件'
                                            .($impact['manual_rules'] > 0
                                                ? '（うち手で登録したもの '.number_format($impact['manual_rules']).'件）'
                                                : '（すべて自動学習。取込のたびに覚え直します）')
                                            .' → 一緒に削除されます';
                                    }
                                    $lines[] = 'よろしいですか?';
                                @endphp
                                <form action="{{ route('categories.destroy', $category) }}" method="POST" class="inline"
                                      onsubmit="return confirm(@js(implode("\n", $lines)));">
                                    @csrf @method('DELETE')
                                    {{-- 画面が表示した件数。サーバ側で数え直して、合わなければ削除しない --}}
                                    <input type="hidden" name="expected_impact"
                                           value="{{ \App\Http\Controllers\CategoryController::impactSignature($impact) }}">
                                    <button class="text-slate-500 hover:text-rose-600">削除</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $hasCascades ? 9 : 7 }}" class="py-6 text-center text-slate-400">カテゴリがありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
@endsection
