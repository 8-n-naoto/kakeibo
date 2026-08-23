@extends('layouts.app')

@section('title', '取引一覧 | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold">取引一覧</h1>
        <div class="flex gap-2">
            <a href="{{ route('transactions.export', request()->only('month', 'category_id')) }}"
               class="bg-white border border-slate-300 text-slate-700 text-sm px-4 py-2 rounded-lg hover:bg-slate-100">CSV出力</a>
            <a href="{{ route('imports.create') }}"
               class="bg-white border border-slate-300 text-slate-700 text-sm px-4 py-2 rounded-lg hover:bg-slate-100">CSV取込</a>
            <a href="{{ route('transactions.create') }}" class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">+ 手動入力</a>
        </div>
    </div>

    <form method="GET" class="flex flex-wrap gap-3 mb-4">
        <input type="month" name="month" value="{{ request('month') }}" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
        <select name="category_id" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
            <option value="">すべてのカテゴリ</option>
            @foreach ($categories as $c)
                <option value="{{ $c->id }}" @selected(request('category_id') == $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        <button class="bg-slate-200 text-slate-700 text-sm px-4 py-1.5 rounded-lg hover:bg-slate-300">絞り込み</button>
    </form>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50">
                <tr class="text-left text-slate-500 border-b border-slate-200">
                    <th class="py-2 px-3">日付</th>
                    <th class="px-3">種別</th>
                    <th class="px-3">カテゴリ</th>
                    <th class="px-3">店名/メモ</th>
                    <th class="px-3 text-right">金額</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions as $t)
                    <tr class="border-b border-slate-100">
                        <td class="py-2 px-3">{{ $t->transaction_date->format('Y/n/j') }}</td>
                        <td class="px-3">{{ $t->type === 'income' ? '収入' : '支出' }}</td>
                        <td class="px-3">
                            <span class="inline-block w-2 h-2 rounded-full mr-1" style="background-color: {{ $t->category?->color ?? '#94a3b8' }}"></span>
                            {{ $t->category?->name ?? '未分類' }}
                        </td>
                        <td class="px-3 text-slate-500">{{ $t->shop_name ?? $t->memo }}</td>
                        <td class="px-3 text-right font-medium {{ $t->type === 'income' ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $t->type === 'income' ? '+' : '-' }}¥{{ number_format($t->amount) }}
                        </td>
                        <td class="px-3 text-right whitespace-nowrap">
                            <a href="{{ route('transactions.edit', $t) }}" class="text-slate-500 hover:text-emerald-600 mr-2">編集</a>
                            <form action="{{ route('transactions.destroy', $t) }}" method="POST" class="inline" onsubmit="return confirm('削除しますか?');">
                                @csrf @method('DELETE')
                                <button class="text-slate-500 hover:text-rose-600">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-slate-400">取引がありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $transactions->links() }}</div>
@endsection
