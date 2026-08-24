@extends('layouts.app')

@section('title', 'カテゴリの追加 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">カテゴリを追加する</h1>

    <form method="POST" action="{{ route('categories.store') }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-xl">
        @csrf
        @include('categories._form', ['category' => null])

        <div class="mt-6 flex gap-3">
            <button class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">保存する</button>
            <a href="{{ route('categories.index') }}" class="text-sm text-slate-500 px-4 py-2">キャンセル</a>
        </div>
    </form>
@endsection
