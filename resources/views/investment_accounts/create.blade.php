@extends('layouts.app')

@section('title', 'NISA/iDeCo口座登録 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">NISA/iDeCo口座を登録</h1>

    <form method="POST" action="{{ route('investment-accounts.store') }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-2xl">
        @csrf
        @include('investment_accounts._form')

        {{-- 同じ年度・種別で登録し直すと、拠出額も評価額もメモも置き換わる --}}
        <label class="mt-4 flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="overwrite" value="1" @checked(old('overwrite') === '1')
                   class="rounded border-slate-300">
            すでにある口座は上書きする
        </label>

        <button class="mt-6 bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">登録する</button>
    </form>
@endsection
