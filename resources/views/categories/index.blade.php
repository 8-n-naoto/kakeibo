@extends('layouts.app')

@section('title', 'カテゴリ管理 | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold">カテゴリ管理</h1>
        <a href="{{ route('categories.create') }}" class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">+ カテゴリを追加</a>
    </div>

    @foreach ([['支出カテゴリ', $expenseCategories], ['収入カテゴリ', $incomeCategories]] as [$heading, $list])
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
            <div class="px-4 py-3 border-b border-slate-200 font-semibold">{{ $heading }}</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-50">
                    <tr class="text-left text-slate-500 border-b border-slate-200">
                        <th class="py-2 px-3">カテゴリ</th>
                        <th class="px-3">固定/変動</th>
                        <th class="px-3 text-right">取引件数</th>
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
                            <td class="px-3 text-right text-slate-500">{{ $category->transactions_count }}</td>
                            <td class="px-3 text-right text-slate-500">{{ $category->sort_order }}</td>
                            <td class="px-3 text-right whitespace-nowrap">
                                <a href="{{ route('categories.edit', $category) }}" class="text-slate-500 hover:text-emerald-600 mr-2">編集</a>
                                <form action="{{ route('categories.destroy', $category) }}" method="POST" class="inline" onsubmit="return confirm('削除しますか? この カテゴリの取引は未分類になります。');">
                                    @csrf @method('DELETE')
                                    <button class="text-slate-500 hover:text-rose-600">削除</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-slate-400">カテゴリがありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
@endsection
