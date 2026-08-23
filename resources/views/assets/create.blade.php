@extends('layouts.app')

@section('title', '資産スナップショット登録 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">資産スナップショットを登録</h1>

    <form method="POST" action="{{ route('assets.store') }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-2xl">
        @csrf
        @include('assets._form')
        <button class="mt-6 bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">登録する</button>
    </form>
@endsection
