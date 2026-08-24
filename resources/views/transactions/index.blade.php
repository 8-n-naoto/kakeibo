@extends('layouts.app')

@section('title', '取引一覧 | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold">取引一覧</h1>
        <div class="flex gap-2">
            <a href="{{ route('transactions.export', $exportParams) }}"
               class="bg-white border border-slate-300 text-slate-700 text-sm px-4 py-2 rounded-lg hover:bg-slate-100">CSV出力</a>
            <a href="{{ route('imports.create') }}"
               class="bg-white border border-slate-300 text-slate-700 text-sm px-4 py-2 rounded-lg hover:bg-slate-100">CSV取込</a>
            <a href="{{ route('transactions.create') }}" class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">+ 手動入力</a>
        </div>
    </div>

    <form method="GET" class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-4">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="col-span-2">
                <label class="block text-xs text-slate-500 mb-1">キーワード(店名・メモ)</label>
                <input type="search" name="keyword" value="{{ request('keyword') }}" placeholder="スーパー、コンビニ など"
                       class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">月</label>
                <input type="month" name="month" value="{{ request('month') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">カテゴリ</label>
                <select name="category_id" class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
                    <option value="">すべて</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected(request('category_id') == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">種別</label>
                <select name="type" class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
                    <option value="">すべて</option>
                    <option value="expense" @selected(request('type') === 'expense')>支出</option>
                    <option value="income" @selected(request('type') === 'income')>収入</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">並び順</label>
                <select name="sort" class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
                    @foreach ($sorts as $value => $label)
                        <option value="{{ $value }}" @selected(request('sort', 'date_desc') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">期間(開始)</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">期間(終了)</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">金額(下限)</label>
                <input type="number" min="0" name="amount_min" value="{{ request('amount_min') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">金額(上限)</label>
                <input type="number" min="0" name="amount_max" value="{{ request('amount_max') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
            </div>
        </div>

        <div class="flex gap-2 mt-3">
            <button class="bg-slate-700 text-white text-sm px-4 py-1.5 rounded-lg hover:bg-slate-800">絞り込み</button>
            <a href="{{ route('transactions.index') }}"
               class="bg-white border border-slate-300 text-slate-600 text-sm px-4 py-1.5 rounded-lg hover:bg-slate-100">条件をクリア</a>
        </div>
    </form>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <div class="text-xs text-slate-500">件数</div>
            <div class="text-lg font-bold">{{ number_format($summary['count']) }} 件</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <div class="text-xs text-slate-500">収入</div>
            <div class="text-lg font-bold text-emerald-600">¥{{ number_format($summary['income']) }}</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <div class="text-xs text-slate-500">支出</div>
            <div class="text-lg font-bold text-rose-600">¥{{ number_format($summary['expense']) }}</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <div class="text-xs text-slate-500">収支</div>
            <div class="text-lg font-bold {{ $summary['balance'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                {{ $summary['balance'] >= 0 ? '+' : '-' }}¥{{ number_format(abs($summary['balance'])) }}
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50">
                <tr class="text-left text-slate-500 border-b border-slate-200">
                    <th class="py-2 px-3 w-8">
                        <input type="checkbox" id="bulk-toggle-all" class="rounded border-slate-300"
                               aria-label="すべて選択">
                    </th>
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
                        <td class="py-2 px-3">
                            {{-- form 属性で下の一括編集フォームに送る（行内の削除フォームとネストさせないため） --}}
                            {{-- old('ids') には入れ子配列が混ざりうる（ids[0][0]=1）。strval に渡すと警告→500 --}}
                            <input type="checkbox" name="ids[]" value="{{ $t->id }}" form="bulk-form"
                                   @checked(in_array((string) $t->id, array_map('strval', array_filter((array) old('ids', []), 'is_scalar')), true))
                                   class="bulk-select rounded border-slate-300"
                                   aria-label="{{ $t->transaction_date->format('Y/n/j') }} の取引を選択">
                        </td>
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
                    <tr><td colspan="7" class="py-6 text-center text-slate-400">取引がありません。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($transactions->total() > 0)
        <form method="POST" action="{{ route('transactions.bulk-update') }}" id="bulk-form"
              class="mt-4 bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            @csrf
            @foreach ($exportParams as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            {{-- 画面が表示した件数。サーバ側で数え直した件数と合わなければ拒否される --}}
            <input type="hidden" name="filtered_count" value="{{ (int) $summary['count'] }}">

                <div class="text-sm font-semibold text-slate-600 mb-1">選んだ取引のカテゴリをまとめて変更</div>
                <p class="text-xs text-slate-500 mb-3">
                    自動分類ルールを直したあと、過去の取引を揃えるのに使います。
                    <span class="text-amber-700">元に戻せないので、対象件数をよく確認してください。</span>
                </p>

                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        {{-- 件数の表示は <option> の外に置く。<option> の中身はテキストしか
                             置けず、<span> はパーサに捨てられて件数が 0 のまま固まる。 --}}
                        <label class="block text-xs text-slate-500 mb-1">
                            対象（チェック <span id="bulk-count">0</span> 件）
                        </label>
                        <select name="scope" id="bulk-scope" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
                            <option value="selected" @selected(old('scope') !== 'filtered')>チェックした取引</option>
                            <option value="filtered" @selected(old('scope') === 'filtered')>この絞り込み結果すべて（{{ number_format($summary['count']) }}件）</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">変更後のカテゴリ</label>
                        {{-- 先頭は必ず「未選択」。ここが「未分類」だと、押し間違い1回で全件のカテゴリが消える --}}
                        <select name="bulk_category_id" id="bulk-category" required
                                class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
                            <option value="">選んでください</option>
                            @foreach ($categories as $c)
                                <option value="{{ $c->id }}" @selected(is_scalar(old('bulk_category_id')) && (string) old('bulk_category_id') === (string) $c->id)>
                                    {{ $c->type === 'income' ? '収入' : '支出' }} / {{ $c->name }}
                                </option>
                            @endforeach
                            <option value="{{ \App\Http\Controllers\TransactionController::CLEAR_CATEGORY }}" id="bulk-category-clear"
                                    @selected(old('bulk_category_id') === \App\Http\Controllers\TransactionController::CLEAR_CATEGORY)>
                                未分類に戻す（チェックした取引のみ）
                            </option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600 pb-1.5">
                        <input type="hidden" name="learn" value="0">
                        <input type="checkbox" name="learn" value="1" class="rounded border-slate-300"
                               @checked(old('learn') === '1')>
                        この店名を次回から自動でこのカテゴリにする
                    </label>
                    <button id="bulk-submit"
                            class="bg-slate-700 text-white text-sm px-5 py-2 rounded-lg hover:bg-slate-800 disabled:opacity-50">
                        まとめて変更する
                    </button>
            </div>
        </form>
    @endif

    <div class="mt-4">{{ $transactions->links() }}</div>

    <script>
        (function () {
            var form = document.getElementById('bulk-form');
            if (!form) { return; }

            var toggleAll = document.getElementById('bulk-toggle-all');
            var scope = document.getElementById('bulk-scope');
            var counter = document.getElementById('bulk-count');
            var submit = document.getElementById('bulk-submit');
            var filteredTotal = {{ (int) $summary['count'] }};

            // チェックボックスは form 属性で紐づいているだけでフォームの外にあるので、
            // form.querySelectorAll ではなく document から拾う
            var checkboxes = document.querySelectorAll('.bulk-select');

            function selected() {
                return document.querySelectorAll('.bulk-select:checked').length;
            }

            function refresh() {
                var count = selected();
                if (counter) { counter.textContent = String(count); }
                if (submit && scope) {
                    submit.disabled = scope.value === 'selected' && count === 0;
                }
            }

            if (toggleAll) {
                toggleAll.addEventListener('change', function () {
                    checkboxes.forEach(function (checkbox) {
                        checkbox.checked = toggleAll.checked;
                    });
                    refresh();
                });
            }

            checkboxes.forEach(function (checkbox) {
                checkbox.addEventListener('change', refresh);
            });

            var category = document.getElementById('bulk-category');
            var clearOption = document.getElementById('bulk-category-clear');

            // 「未分類に戻す」は絞り込み結果すべてには使えない(サーバ側でも拒否する)
            function syncClearOption() {
                if (!clearOption || !scope) { return; }
                var filtered = scope.value === 'filtered';
                clearOption.disabled = filtered;
                if (filtered && category && category.value === clearOption.value) {
                    category.value = '';
                }
            }

            if (scope) {
                scope.addEventListener('change', function () {
                    syncClearOption();
                    refresh();
                });
            }

            form.addEventListener('submit', function (event) {
                var count = scope && scope.value === 'filtered' ? filteredTotal : selected();

                if (count === 0) {
                    event.preventDefault();
                    return;
                }

                if (!window.confirm(count + ' 件のカテゴリをまとめて変更します。元に戻せません。よろしいですか?')) {
                    event.preventDefault();
                }
            });

            syncClearOption();
            refresh();
        })();
    </script>
@endsection
