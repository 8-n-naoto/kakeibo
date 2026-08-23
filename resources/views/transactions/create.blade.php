@extends('layouts.app')

@section('title', '手動入力 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">取引を手動入力</h1>

    <form method="POST" action="{{ route('transactions.store') }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-2xl">
        @csrf
        @include('transactions._form')
        <button class="mt-6 bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">登録する</button>
    </form>
@endsection
