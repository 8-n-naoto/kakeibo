@extends('layouts.app')

@section('title', 'カテゴリの編集 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">カテゴリを編集する</h1>

    <form method="POST" action="{{ route('categories.update', $category) }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-xl">
        @csrf @method('PUT')
        @include('categories._form')

        <div class="mt-6 flex gap-3">
            <button class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">更新する</button>
            <a href="{{ route('categories.index') }}" class="text-sm text-slate-500 px-4 py-2">キャンセル</a>
        </div>
    </form>
@endsection
