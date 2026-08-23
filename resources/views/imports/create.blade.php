@extends('layouts.app')

@section('title', 'CSV取込 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">クレジットカード明細などのCSVを取り込む</h1>

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
            <li>登録済みの取引と日付・金額・店名が一致する行は、重複候補として自動的にチェックを外します。</li>
        </ul>

        <div class="mt-6">
            <button class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">解析する</button>
        </div>
    </form>
@endsection
