@extends('layouts.app')

@section('title', 'CSV取込 | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold">クレジットカード明細などのCSVを取り込む</h1>
        <a href="{{ route('imports.batches') }}" class="text-sm text-slate-500 hover:text-slate-700">取込履歴 / 取り消し</a>
    </div>

    <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data"
          class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-xl">
        @csrf

        <label class="block text-sm text-slate-600 mb-1">CSVファイル</label>
        <input type="file" name="file" accept=".csv,text/csv" required
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">

        <ul class="text-xs text-slate-500 mt-4 space-y-1 list-disc list-inside">
            <li>文字コードは Shift_JIS / UTF-8 のどちらでも読み込めます。</li>
            <li>「利用日」「利用店名」「利用金額」などの見出しから列を自動判定します。</li>
            <li>金額がマイナスの行は返金とみなして収入として取り込みます。</li>
            <li>登録済みの取引と日付・種別・金額・店名が一致する行は、重複候補として自動的にチェックを外します。</li>
            <li>一度に取り込めるのは2,000行までです。</li>
            <li>間違えて取り込んだ場合は「取込履歴」からまとめて取り消せます。</li>
            <li>取り込みたくない店は、プレビューで「今後取り込まない」にチェックすると次回から自動で外れます。</li>
        </ul>

        <div class="mt-6">
            <button class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">解析する</button>
        </div>
    </form>

    @if ($ignoreRules->isNotEmpty())
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-xl mt-6">
            <h2 class="font-semibold text-sm mb-1">取り込まない店</h2>
            <p class="text-xs text-slate-500 mb-3">
                プレビューで「今後取り込まない」にチェックした店です。取込時は既定でチェックが外れます（行が消えるわけではありません）。
                <strong>店名の一部が一致する行も対象になります</strong>（「イオン」を登録すると「イオンシネマ」も外れます）。
                毎月変わる文字（「7月分」など）が入ったまま登録すると翌月は一致しないので、プレビューで店名を短くしてからチェックしてください。
            </p>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($ignoreRules as $rule)
                    <li class="flex items-center justify-between py-2">
                        <span>{{ $rule->display_name ?? $rule->pattern }}</span>
                        <form method="POST" action="{{ route('imports.ignore-rules.destroy', $rule) }}">
                            @csrf
                            @method('DELETE')
                            <button class="text-xs text-slate-500 hover:text-rose-600">解除する</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
