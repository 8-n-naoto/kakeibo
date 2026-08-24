@extends('layouts.app')

@section('title', 'レシート読込 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-2">レシート・明細を画像から読み込み</h1>
    <p class="text-sm text-slate-500 mb-6">
        レシートや明細書の写真をアップロードすると、AIが日付・店名・金額・カテゴリ候補を自動で読み取ります。
        溜めた分をまとめて処理できるよう、一度に{{ $maxFiles }}枚まで選べます。
        アップロードは保存するだけなので待たされません。読み取りは次の画面で1枚ずつ順番に行い、進み具合が見えます。
    </p>

    @if ($pendingCount > 0)
        <div class="mb-6 rounded-lg bg-sky-50 border border-sky-200 text-sky-800 px-4 py-3 text-sm">
            まだ家計簿に登録していないレシートが {{ $pendingCount }} 件あります。
            <a href="{{ route('receipts.pending') }}" class="underline font-semibold hover:text-sky-900">まとめて確認する</a>
        </div>
    @endif

    <form method="POST" action="{{ route('receipts.store') }}" enctype="multipart/form-data"
          class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-xl">
        @csrf
        <label class="block text-sm text-slate-600 mb-2">
            画像ファイル(JPG / PNG / WebP / GIF / HEIC、1枚あたり最大10MB、最大{{ $maxFiles }}枚)
        </label>
        <input type="file" name="images[]" accept="image/*,.heic,.heif" multiple required
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mb-4">
        <p class="text-xs text-slate-400 mb-4">iPhoneで撮った HEIC の写真もそのまま選べます。</p>
        <button class="bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">
            アップロードして解析する
        </button>
    </form>
@endsection
