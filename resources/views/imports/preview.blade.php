@extends('layouts.app')

@section('title', 'CSV取込の確認 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-2">取り込む内容を確認する</h1>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-6">
        <p class="text-sm text-slate-600">
            取込対象 <span class="font-semibold text-slate-800">{{ $importableCount }}</span>件
            / 重複候補 {{ $duplicateCount }}件
            / 読み取り失敗 <span class="{{ $errorCount > 0 ? 'text-rose-600 font-semibold' : '' }}">{{ $errorCount }}</span>件
        </p>
        <p class="text-sm text-slate-600 mt-2">
            読み取れた合計：支出 <span class="font-semibold text-slate-800">¥{{ number_format($expenseTotal) }}</span>
            @if ($incomeTotal > 0)
                / 入金・返金 ¥{{ number_format($incomeTotal) }}
            @endif
        </p>
        <p class="text-xs text-slate-500 mt-1">
            明細に書かれた請求額と見比べてください。合わない場合は、金額の列が正しく読めていないか、行が抜けています。
        </p>
        @if ($ignoredCount > 0)
            <p class="text-xs text-slate-600 mt-2">
                {{ $ignoredCount }}件は「取り込まない」ルールに当たります。既定でチェックを外しています（落としてはいません）。
            </p>
        @endif
        @if ($receiptMatchCount > 0)
            <p class="text-xs text-amber-700 mt-2">
                {{ $receiptMatchCount }}件は、すでにレシートから登録されている支払いと金額が一致します（店名の書き方が違うだけの可能性があります）。
                二重に計上しないよう、既定でチェックを外しています。
            </p>
        @endif
        @if ($assumedYearCount > 0)
            <p class="text-xs text-amber-700 mt-2">
                {{ $assumedYearCount }}件は日付に年が書かれていないため、ファイル内の他の行から年を補いました。日付欄を確認してください。
            </p>
        @endif
        @if ($errorCount > 0)
            <p class="text-xs text-slate-500 mt-2">
                読み取れなかった行も、日付と金額を手で入れればチェックを付けて取り込めます。
            </p>
        @endif
    </div>

    @php
        $mappingKeys = ['mapping', 'mapping.header_row', 'mapping.date', 'mapping.shop', 'mapping.amount', 'mapping.income'];
    @endphp
    @if ($columns !== [])
    <details class="bg-white rounded-xl shadow-sm border border-slate-200 mb-6" @if ($errors->hasAny($mappingKeys)) open @endif>
        <summary class="cursor-pointer px-4 py-3 text-sm text-slate-700">
            列の読み取りがおかしいときはここを開く
            @if ($profileApplied)
                <span class="ml-2 text-xs text-emerald-700">前回この形のCSVで指定した列を使いました</span>
            @endif
        </summary>

        <form method="POST" action="{{ route('imports.remap') }}" class="border-t border-slate-100 px-4 py-4">
            @csrf

            @if ($errors->hasAny($mappingKeys))
                <p class="mb-3 text-sm text-rose-600">{{ $errors->first($mappingKeys[0]) ?: $errors->first($mappingKeys[1]) }}</p>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 text-sm">
                @php
                    $fields = [
                        'header_row' => 'ヘッダー行',
                        'date' => '日付の列',
                        'shop' => '店名の列',
                        'amount' => '出金（支出）の列',
                        'income' => '入金の列',
                    ];
                @endphp
                @foreach ($fields as $key => $label)
                    @php
                        // ヘッダー行は「行」、それ以外は「列」を選ぶ。
                        // 列の一覧を使い回すと、列数ぶんの行しか選べなくなる
                        $options = $key === 'header_row' ? $rowLabels : $columns;
                        $current = old("mapping.{$key}", $mapping[$key] ?? null);
                        $current = is_numeric($current) ? (int) $current : null;
                    @endphp
                    <label class="block">
                        <span class="block text-xs text-slate-500 mb-1">{{ $label }}</span>
                        <select name="mapping[{{ $key }}]"
                                class="w-full border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
                            <option value="">{{ $key === 'header_row' ? 'ヘッダー行なし' : '使わない' }}</option>
                            @foreach ($options as $index => $optionLabel)
                                <option value="{{ $index }}" @selected($current === $index)>{{ $optionLabel }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>

            <p class="text-xs text-slate-500 mt-3">
                日付の列と、金額（出金か入金）のどちらかは必ず指定してください。
                登録まで進むと、この指定を「{{ $fileName ?? 'このCSV' }}」と同じ並びのファイル用に覚えます。
            </p>

            <button class="mt-3 bg-slate-700 text-white text-sm px-4 py-2 rounded-lg hover:bg-slate-800">
                この指定で読み直す
            </button>
        </form>
    </details>
    @endif

    <form method="POST" action="{{ route('imports.confirm') }}"
          onsubmit="this.querySelectorAll('button').forEach(b => b.disabled = true);">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50">
                    <tr class="text-left text-slate-500 border-b border-slate-200">
                        <th class="py-2 px-3">取込</th>
                        <th class="px-3">行</th>
                        <th class="px-3">日付</th>
                        <th class="px-3">種別</th>
                        <th class="px-3">店名</th>
                        <th class="px-3">カテゴリ</th>
                        <th class="px-3 text-right">金額</th>
                        <th class="px-3 text-center" title="店名の一部が一致する行も対象になります">今後<br>取り込まない</th>
                        <th class="px-3">状態</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $hasOldRows = old('rows') !== null;
                        // old() には配列が入りうる（rows[0][amount][]=1）。そのまま出すと
                        // htmlspecialchars が TypeError を投げ、プレビュー自体が開けなくなる
                        $oldCell = function (int $index, string $key, mixed $fallback) {
                            $value = old("rows.{$index}.{$key}", $fallback);

                            // null は「ユーザーが消した」の意味（ConvertEmptyStringsToNull）。
                            // ここで元に戻すと、消したはずの値が黙って復活する。
                            return is_array($value) ? $fallback : $value;
                        };
                    @endphp
                    @foreach ($rows as $i => $row)
                        <tr class="border-b border-slate-100 {{ $row['importable'] ? '' : 'bg-rose-50/50' }}">
                            <td class="py-2 px-3">
                                <input type="checkbox" name="rows[{{ $i }}][import]" value="1"
                                       @checked($hasOldRows
                                           ? ! empty(old("rows.{$i}.import"))
                                           : ($row['importable'] && ! $row['duplicate'] && empty($row['receipt_match_of']) && empty($row['ignored_by'])))
                                       aria-label="{{ $row['line'] }}行目を取り込む">
                            </td>
                            <td class="px-3 text-xs text-slate-400">{{ $row['line'] }}</td>
                            <td class="px-3">
                                <input type="date" name="rows[{{ $i }}][transaction_date]"
                                       value="{{ $oldCell($i, 'transaction_date', $row['transaction_date']) }}"
                                       class="border rounded px-2 py-1 text-sm {{ $errors->has("rows.{$i}.transaction_date") ? 'border-rose-400' : (($row['assumed_year'] ?? false) ? 'border-amber-400' : 'border-slate-300') }}">
                            </td>
                            <td class="px-3">
                                <select name="rows[{{ $i }}][type]" data-type-control class="border border-slate-300 rounded px-2 py-1 text-sm">
                                    <option value="expense" @selected($oldCell($i, 'type', $row['type']) === 'expense')>支出</option>
                                    <option value="income" @selected($oldCell($i, 'type', $row['type']) === 'income')>収入</option>
                                </select>
                            </td>
                            <td class="px-3">
                                <input type="text" name="rows[{{ $i }}][shop_name]"
                                       value="{{ $oldCell($i, 'shop_name', $row['shop_name']) }}" maxlength="255"
                                       class="border border-slate-300 rounded px-2 py-1 text-sm w-48">
                            </td>
                            <td class="px-3">
                                <select name="rows[{{ $i }}][category_id]" data-category-select
                                        class="border border-slate-300 rounded px-2 py-1 text-sm">
                                    @include('partials.category-options', [
                                        'categories' => $categories,
                                        'selectedCategoryId' => $oldCell($i, 'category_id', $row['category_id']),
                                    ])
                                </select>
                                @error("rows.{$i}.category_id")
                                    <span class="block text-xs text-rose-600">{{ $message }}</span>
                                @enderror
                            </td>
                            <td class="px-3 text-right">
                                <input type="number" name="rows[{{ $i }}][amount]"
                                       value="{{ $oldCell($i, 'amount', $row['amount']) }}" min="0"
                                       max="{{ \App\Support\DomainLimits::MAX_AMOUNT }}"
                                       class="border border-slate-300 rounded px-2 py-1 text-sm w-28 text-right {{ $errors->has("rows.{$i}.amount") ? 'border-rose-400' : 'border-slate-300' }}">
                            </td>
                            <td class="px-3 text-center">
                                @if (! empty($row['shop_name']))
                                    <input type="checkbox" name="rows[{{ $i }}][ignore]" value="1"
                                           @checked(old("rows.{$i}.ignore"))
                                           aria-label="{{ $row['shop_name'] }}を今後取り込まない">
                                @endif
                            </td>
                            <td class="px-3 text-xs">
                                @if (! $row['importable'])
                                    <span class="text-rose-600">{{ $row['error'] }}</span>
                                @elseif ($row['duplicate'])
                                    <span class="text-amber-600 whitespace-nowrap">重複候補</span>
                                    @if (! empty($row['duplicate_of']))
                                        <span class="block text-slate-400">登録済み: {{ $row['duplicate_of'] }}</span>
                                    @endif
                                @elseif (! empty($row['ignored_by']))
                                    <span class="text-slate-500 whitespace-nowrap">取り込まない設定</span>
                                    <span class="block text-slate-400">「{{ $row['ignored_by'] }}」のルール</span>
                                @elseif (! empty($row['receipt_match_of']))
                                    <span class="text-amber-600 whitespace-nowrap">レシートと同じ支払いかも</span>
                                    <span class="block text-slate-400">{{ $row['receipt_match_of'] }}</span>
                                @elseif (! empty($row['repeat_of_line']))
                                    <span class="text-amber-600 whitespace-nowrap">{{ $row['repeat_of_line'] }}行目と同じ内容</span>
                                    <span class="block text-slate-400">同じ買い物を2回した場合はそのままで大丈夫です</span>
                                @elseif ($row['assumed_year'] ?? false)
                                    <span class="text-amber-600 whitespace-nowrap">年を補完</span>
                                @else
                                    <span class="text-emerald-600 whitespace-nowrap">取込可</span>
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
