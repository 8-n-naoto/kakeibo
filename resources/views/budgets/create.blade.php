@extends('layouts.app')

@section('title', '予算の設定 | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-6">予算を設定する</h1>

    <form method="POST" action="{{ route('budgets.store') }}" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 max-w-xl">
        @csrf
        @include('budgets._form', ['budget' => null])

        {{-- 同じカテゴリ・同じ月の予算は、メモごと置き換わる。黙って上書きしない --}}
        <label class="mt-4 flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="overwrite" value="1" @checked(old('overwrite') === '1')
                   class="rounded border-slate-300">
            すでにある予算は上書きする
        </label>

        <div class="mt-6 flex gap-3">
            <button class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">保存する</button>
            <a href="{{ route('budgets.index') }}" class="text-sm text-slate-500 px-4 py-2">キャンセル</a>
        </div>
    </form>
@endsection
