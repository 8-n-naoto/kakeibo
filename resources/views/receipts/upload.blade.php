@extends('layouts.app')

@section('title', 'レシート読込 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-2">レシート・明細を画像から読み込み</h1>
    <p class="text-sm text-slate-500 mb-6">レシートや明細書の写真をアップロードすると、AIが日付・店名・金額・カテゴリ候補を自動で読み取ります。内容は次の画面で確認・修正してから登録されます。</p>

    <form method="POST" action="{{ route('receipts.store') }}" enctype="multipart/form-data"
          class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-xl">
        @csrf
        <label class="block text-sm text-slate-600 mb-2">画像ファイル(JPG/PNG、最大10MB)</label>
        <input type="file" name="image" accept="image/*" required
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mb-4">
        <button class="bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">
            アップロードして解析する
        </button>
    </form>
@endsection
