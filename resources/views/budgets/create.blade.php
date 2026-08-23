@extends('layouts.app')

@section('title', '予算の設定 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">予算を設定する</h1>

    <form method="POST" action="{{ route('budgets.store') }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-xl">
        @csrf
        @include('budgets._form', ['budget' => null])

        <div class="mt-6 flex gap-3">
            <button class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">保存する</button>
            <a href="{{ route('budgets.index') }}" class="text-sm text-slate-500 px-4 py-2">キャンセル</a>
        </div>
    </form>
@endsection
