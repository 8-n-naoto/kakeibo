@extends('layouts.app')

@section('title', 'NISA/iDeCo口座編集 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">NISA/iDeCo口座を編集</h1>

    <form method="POST" action="{{ route('investment-accounts.update', $account) }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-2xl">
        @csrf
        @method('PUT')
        @include('investment_accounts._form')
        <button class="mt-6 bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">更新する</button>
    </form>
@endsection
