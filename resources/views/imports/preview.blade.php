@extends('layouts.app')

@section('title', 'CSV取込の確認 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-2">取り込む内容を確認する</h1>
    <p class="text-sm text-slate-500 mb-6">
        取込対象 {{ $importableCount }}件 / 重複候補 {{ $duplicateCount }}件 / 読み取り失敗 {{ $errorCount }}件
    </p>

    <form method="POST" action="{{ route('imports.confirm') }}">
        @csrf

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50">
                    <tr class="text-left text-slate-500 border-b border-slate-200">
                        <th class="py-2 px-3">取込</th>
                        <th class="px-3">日付</th>
                        <th class="px-3">種別</th>
                        <th class="px-3">店名</th>
                        <th class="px-3">カテゴリ</th>
                        <th class="px-3 text-right">金額</th>
                        <th class="px-3">状態</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $row)
                        <tr class="border-b border-slate-100 {{ $row['importable'] ? '' : 'bg-rose-50/50' }}">
                            <td class="py-2 px-3">
                                <input type="checkbox" name="rows[{{ $i }}][import]" value="1"
                                       @checked($row['importable'] && ! $row['duplicate'])
                                       @disabled(! $row['importable'])>
                            </td>
                            <td class="px-3">
                                <input type="date" name="rows[{{ $i }}][transaction_date]" value="{{ $row['transaction_date'] }}"
                                       class="border border-slate-300 rounded px-2 py-1 text-sm">
                            </td>
                            <td class="px-3">
                                <select name="rows[{{ $i }}][type]" class="border border-slate-300 rounded px-2 py-1 text-sm">
                                    <option value="expense" @selected($row['type'] === 'expense')>支出</option>
                                    <option value="income" @selected($row['type'] === 'income')>収入</option>
                                </select>
                            </td>
                            <td class="px-3">
                                <input type="text" name="rows[{{ $i }}][shop_name]" value="{{ $row['shop_name'] }}" maxlength="255"
                                       class="border border-slate-300 rounded px-2 py-1 text-sm w-48">
                            </td>
                            <td class="px-3">
                                <select name="rows[{{ $i }}][category_id]" class="border border-slate-300 rounded px-2 py-1 text-sm">
                                    <option value="">未分類</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}" @selected($row['category_id'] === $category->id)>{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 text-right">
                                <input type="number" name="rows[{{ $i }}][amount]" value="{{ $row['amount'] }}" min="0"
                                       class="border border-slate-300 rounded px-2 py-1 text-sm w-28 text-right">
                            </td>
                            <td class="px-3 text-xs whitespace-nowrap">
                                @if (! $row['importable'])
                                    <span class="text-rose-600">{{ $row['error'] }}</span>
                                @elseif ($row['duplicate'])
                                    <span class="text-amber-600">重複候補</span>
                                @else
                                    <span class="text-emerald-600">取込可</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6 flex gap-3">
            <button class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">選択した行を登録する</button>
            <a href="{{ route('imports.create') }}" class="text-sm text-slate-500 px-4 py-2">やり直す</a>
        </div>
    </form>
@endsection
