@extends('layouts.app')

@section('title', '読み取り結果の確認 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">読み取り結果を確認</h1>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <img src="{{ asset('storage/'.$receiptImage->path) }}" alt="レシート画像" class="w-full rounded-lg">
        </div>

        <form method="POST" action="{{ route('receipts.confirm.store', $receiptImage) }}"
              class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            @csrf
            <p class="text-sm text-slate-500 mb-4">AIによる読み取り結果です。内容を確認し、必要に応じて修正してから登録してください。</p>

            <div class="grid grid-cols-1 gap-4">
                <div>
                    <label class="block text-sm text-slate-600 mb-1">日付</label>
                    <input type="date" name="transaction_date" required
                           value="{{ old('transaction_date', $parsed['transaction_date'] ?? now()->format('Y-m-d')) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">種別</label>
                    <select name="type" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                        <option value="expense" @selected(old('type', $parsed['type'] ?? 'expense') === 'expense')>支出</option>
                        <option value="income" @selected(old('type', $parsed['type'] ?? 'expense') === 'income')>収入</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">カテゴリ</label>
                    <select name="category_id" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">未分類</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}" @selected(old('category_id', $suggestedCategoryId) == $c->id)>
                                {{ $c->type === 'income' ? '収入' : '支出' }} / {{ $c->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">金額(円)</label>
                    <input type="number" name="amount" min="0" required
                           value="{{ old('amount', $parsed['total_amount'] ?? '') }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">店名</label>
                    <input type="text" name="shop_name" value="{{ old('shop_name', $parsed['shop_name'] ?? '') }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">メモ</label>
                    <input type="text" name="memo" value="{{ old('memo', $parsed['memo'] ?? '') }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
            </div>

            @if (! empty($parsed['items']))
                <div class="mt-4 text-xs text-slate-500">
                    <div class="font-semibold mb-1">読み取れた品目:</div>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach ($parsed['items'] as $item)
                            <li>{{ $item['name'] ?? '(不明)' }} — ¥{{ number_format($item['amount'] ?? 0) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <button class="mt-6 bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">
                この内容で家計簿に登録する
            </button>
        </form>
    </div>
@endsection
