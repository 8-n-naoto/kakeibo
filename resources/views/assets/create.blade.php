@extends('layouts.app')

@section('title', '資産スナップショット登録 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">資産スナップショットを登録</h1>

    <form method="POST" action="{{ route('assets.store') }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-2xl">
        @csrf
        @include('assets._form')

        {{-- 対象月は月初に丸めるので、同じ月に2回登録すると置き換わる。黙って上書きしない --}}
        <label class="mt-4 flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="overwrite" value="1" @checked(old('overwrite') === '1')
                   class="rounded border-slate-300">
            すでにある月は上書きする
        </label>

        <button class="mt-6 bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">登録する</button>
    </form>
@endsection
