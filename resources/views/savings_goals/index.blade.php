@extends('layouts.app')

@section('title', '貯蓄目標 | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold">貯蓄目標</h1>
        <a href="{{ route('savings-goals.create') }}" class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">+ 目標を追加</a>
    </div>

    @if ($goals->isEmpty())
        <p class="text-sm text-slate-400">まだ貯蓄目標がありません。</p>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        @foreach ($goals as $goal)
            @php
                $rate = min(100, $goal->progress_rate);
                $over = $goal->progress_rate > 100;
            @endphp
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <div class="flex items-start justify-between mb-2">
                    <h2 class="font-semibold">{{ $goal->name }}</h2>
                    <div class="text-xs whitespace-nowrap">
                        <a href="{{ route('savings-goals.edit', $goal) }}" class="text-slate-500 hover:text-emerald-600 mr-2">編集</a>
                        <form action="{{ route('savings-goals.destroy', $goal) }}" method="POST" class="inline" onsubmit="return confirm('削除しますか?');">
                            @csrf @method('DELETE')
                            <button class="text-slate-500 hover:text-rose-600">削除</button>
                        </form>
                    </div>
                </div>

                <div class="text-sm text-slate-500 mb-2">
                    ¥{{ number_format($goal->current_amount) }} / ¥{{ number_format($goal->target_amount) }}
                </div>

                <div class="w-full h-2.5 bg-slate-100 rounded-full overflow-hidden mb-2">
                    <div class="h-full {{ $over ? 'bg-amber-500' : 'bg-emerald-600' }}" style="width: {{ $rate }}%"></div>
                </div>

                <div class="flex items-center justify-between text-xs text-slate-500">
                    <span>{{ $goal->progress_rate }}% 達成</span>
                    @if ($goal->target_date)
                        <span>期限: {{ $goal->target_date->format('Y年n月') }}</span>
                    @endif
                </div>

                @if ($goal->target_date)
                    <div class="mt-2 text-xs text-slate-400">
                        @if ($goal->monthly_required_amount > 0)
                            達成には毎月約 ¥{{ number_format($goal->monthly_required_amount) }} の貯蓄が必要
                        @else
                            目標を達成しています 🎉
                        @endif
                    </div>
                @endif

                @if ($goal->memo)
                    <p class="mt-2 text-xs text-slate-400">{{ $goal->memo }}</p>
                @endif
            </div>
        @endforeach
    </div>
@endsection
