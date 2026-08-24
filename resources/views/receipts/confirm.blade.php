@extends('layouts.app')

@section('title', '読み取り結果の確認 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">読み取り結果を確認</h1>

    @if (! empty($parsed['warnings']))
        <div class="mb-6 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            <span class="font-semibold">目で確かめてください：</span>
            {{ implode('、', $parsed['warnings']) }}
        </div>
    @endif

    @if ($registeredTransactions->isNotEmpty())
        <div class="mb-6 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm">
            <div class="font-semibold mb-1">このレシートはすでに登録済みです。</div>
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($registeredTransactions as $registered)
                    <li>
                        {{ $registered->transaction_date->format('Y/m/d') }}
                        / {{ $registered->category?->name ?? '未分類' }}
                        / {{ $registered->shop_name ?: '(店名なし)' }}
                        / ¥{{ number_format($registered->amount) }}
                        <a href="{{ route('transactions.edit', $registered) }}" class="underline hover:text-amber-900">編集</a>
                    </li>
                @endforeach
            </ul>
            <p class="mt-1">二重計上を防ぐため、この画面からの再登録はできません。</p>
        </div>
    @endif

    @php
        $items = (! empty($parsed['items']) && is_array($parsed['items'])) ? array_values($parsed['items']) : [];
        $mode = old('mode', 'total');
        // 入力エラーで戻ってきたときは、外したチェックが復活しないよう old をそのまま使う
        $hasOldItems = old('items') !== null;
        $receiptTotal = $parsed['total_amount'] ?? null;
    @endphp

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <img src="{{ asset('storage/'.$receiptImage->path) }}" alt="レシート画像" class="w-full rounded-lg">
        </div>

        <form method="POST" action="{{ route('receipts.confirm.store', $receiptImage) }}"
              class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            @csrf
            <p class="text-sm text-slate-500 mb-4">AIによる読み取り結果です。内容を確認し、必要に応じて修正してから登録してください。</p>

            @if ($items !== [])
                <div class="mb-4 flex flex-wrap gap-4 text-sm bg-slate-50 rounded-lg px-3 py-2">
                    <label class="flex items-center gap-2">
                        <input type="radio" name="mode" value="total" class="mode-switch" @checked($mode !== 'items')>
                        合計を1件で登録
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="mode" value="items" class="mode-switch" @checked($mode === 'items')>
                        品目ごとに分ける（{{ count($items) }}件）
                    </label>
                </div>
            @else
                <input type="hidden" name="mode" value="total">
            @endif

            {{-- 日付・種別・店名はどちらのモードでも共通 --}}
            <div class="grid grid-cols-1 gap-4">
                <div>
                    <label class="block text-sm text-slate-600 mb-1">日付</label>
                    <input type="date" name="transaction_date" required
                           value="{{ old('transaction_date', $parsed['transaction_date'] ?? now()->format('Y-m-d')) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">種別</label>
                    <select name="type" required data-type-control class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                        <option value="expense" @selected(old('type', $parsed['type'] ?? 'expense') === 'expense')>支出</option>
                        <option value="income" @selected(old('type', $parsed['type'] ?? 'expense') === 'income')>収入</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">店名</label>
                    <input type="text" name="shop_name" value="{{ old('shop_name', $parsed['shop_name'] ?? '') }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
            </div>

            {{-- 合計を1件で登録するときだけ使う項目 --}}
            <div data-mode-panel="total" class="grid grid-cols-1 gap-4 mt-4">
                <div>
                    <label class="block text-sm text-slate-600 mb-1">カテゴリ</label>
                    <select name="category_id" data-category-select
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                        @include('partials.category-options', [
                            'categories' => $categories,
                            'selectedCategoryId' => old('category_id', $suggestedCategoryId),
                        ])
                    </select>
                    @error('category_id')
                        <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">金額(円)</label>
                    <input type="number" name="amount" min="0"
                           value="{{ old('amount', $receiptTotal ?? '') }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-slate-600 mb-1">メモ</label>
                    <input type="text" name="memo" value="{{ old('memo', $parsed['memo'] ?? '') }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                </div>
            </div>

            {{-- 品目ごとに分けて登録するときだけ使う項目 --}}
            @if ($items !== [])
                <div data-mode-panel="items" class="mt-4">
                    <div class="text-sm font-semibold text-slate-600 mb-2">読み取れた品目</div>
                    <div class="space-y-2">
                        @foreach ($items as $index => $item)
                            @php
                                $item = is_array($item) ? $item : [];
                                // 値引き行（マイナス）は取引として登録できないので既定で外す
                                $itemIsDiscount = is_numeric($item['amount'] ?? null) && (int) $item['amount'] < 0;
                            @endphp
                            <div class="grid grid-cols-[auto_1fr] gap-2 items-start border border-slate-200 rounded-lg p-2">
                                <input type="checkbox" name="items[{{ $index }}][selected]" value="1"
                                       class="item-select mt-2 rounded border-slate-300"
                                       @checked($hasOldItems ? old("items.{$index}.selected") : ! $itemIsDiscount)>
                                <div class="grid grid-cols-2 gap-2">
                                    <input type="text" name="items[{{ $index }}][name]" maxlength="255" placeholder="品目名"
                                           value="{{ old("items.{$index}.name", $item['name'] ?? '') }}"
                                           class="col-span-2 border border-slate-300 rounded-lg px-2 py-1 text-sm">
                                    <input type="number" min="0" name="items[{{ $index }}][amount]" placeholder="金額"
                                           value="{{ old("items.{$index}.amount", $item['amount'] ?? '') }}"
                                           class="item-amount border border-slate-300 rounded-lg px-2 py-1 text-sm">
                                    @if ($itemIsDiscount)
                                        <p class="col-span-2 text-xs text-amber-700">
                                            値引き行は品目ごとの登録に含められません（取引の金額をマイナスにできないため）。合計での登録をお使いください。
                                        </p>
                                    @endif
                                    <select name="items[{{ $index }}][category_id]" data-category-select
                                            class="border border-slate-300 rounded-lg px-2 py-1 text-sm">
                                        @include('partials.category-options', [
                                            'categories' => $categories,
                                            'selectedCategoryId' => old("items.{$index}.category_id", $suggestedCategoryId),
                                        ])
                                    </select>
                                    @error("items.{$index}.category_id")
                                        <p class="col-span-2 text-xs text-rose-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-500 mt-2">
                        選んだ品目の合計: <span id="items-total" class="font-semibold">¥0</span>
                        @if (! is_null($receiptTotal))
                            <span class="text-slate-400">/ レシート合計 ¥{{ number_format((int) $receiptTotal) }}</span>
                        @endif
                    </p>
                    <p class="text-xs text-slate-400 mt-1">品目名はメモ欄に入ります。日付・種別・店名は上の入力を使います。</p>
                </div>
            @endif

            @if ($registeredTransactions->isEmpty())
                <button class="mt-6 bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">
                    この内容で家計簿に登録する
                </button>
            @else
                <a href="{{ route('transactions.index') }}"
                   class="mt-6 inline-block bg-slate-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-slate-700">
                    取引一覧を見る
                </a>
            @endif
        </form>
    </div>

    @if ($items !== [])
        <script>
            (function () {
                var switches = document.querySelectorAll('.mode-switch');
                var panels = document.querySelectorAll('[data-mode-panel]');
                var totalLabel = document.getElementById('items-total');

                function currentMode() {
                    var checked = document.querySelector('.mode-switch:checked');
                    return checked ? checked.value : 'total';
                }

                function applyMode() {
                    var mode = currentMode();
                    panels.forEach(function (panel) {
                        panel.style.display = panel.dataset.modePanel === mode ? '' : 'none';
                    });
                }

                function updateTotal() {
                    var sum = 0;
                    document.querySelectorAll('[data-mode-panel="items"] .item-select').forEach(function (checkbox) {
                        if (!checkbox.checked) return;
                        var row = checkbox.closest('div.grid');
                        var amount = row ? row.querySelector('.item-amount') : null;
                        sum += amount ? (parseInt(amount.value, 10) || 0) : 0;
                    });
                    if (totalLabel) {
                        totalLabel.textContent = '¥' + sum.toLocaleString('ja-JP');
                    }
                }

                switches.forEach(function (input) { input.addEventListener('change', applyMode); });
                document.querySelectorAll('.item-select, .item-amount').forEach(function (input) {
                    input.addEventListener('change', updateTotal);
                    input.addEventListener('input', updateTotal);
                });

                applyMode();
                updateTotal();
            })();
        </script>
    @endif
@endsection
