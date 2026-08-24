@extends('layouts.app')

@section('title', '定期支出の編集 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">定期支出を編集</h1>

    <form method="POST" action="{{ route('recurring.update', $recurring) }}"
          class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-2xl">
        @csrf @method('PUT')
        @include('recurring._form')
        <div class="mt-6 flex gap-2">
            <button class="bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">更新する</button>
            <a href="{{ route('recurring.index') }}"
               class="bg-white border border-slate-300 text-slate-600 text-sm px-5 py-2 rounded-lg hover:bg-slate-100">戻る</a>
        </div>
    </form>
@endsection
