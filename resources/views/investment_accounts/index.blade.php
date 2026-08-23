@extends('layouts.app')

@section('title', 'NISA/iDeCo管理 | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold">NISA/iDeCo管理</h1>
        <a href="{{ route('investment-accounts.create') }}" class="bg-emerald-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-emerald-700">+ 口座を登録</a>
    </div>

    <form method="GET" class="flex items-center gap-2 mb-4">
        <label class="text-sm text-slate-500">年度</label>
        <select name="fiscal_year" onchange="this.form.submit()" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
            @foreach ($years as $y)
                <option value="{{ $y }}" @selected($y == $fiscalYear)>{{ $y }}年度</option>
            @endforeach
        </select>
    </form>

    @if ($accounts->isEmpty())
        <p class="text-sm text-slate-400">{{ $fiscalYear }}年度の口座がまだ登録されていません。</p>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        @foreach ($accounts as $account)
            @php $usage = min(100, $account->usage_rate); @endphp
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                <div class="flex items-start justify-between mb-2">
                    <h2 class="font-semibold">{{ $account->account_type_label }}</h2>
                    <div class="text-xs whitespace-nowrap">
                        <a href="{{ route('investment-accounts.edit', $account) }}" class="text-slate-500 hover:text-emerald-600 mr-2">編集</a>
                        <form action="{{ route('investment-accounts.destroy', $account) }}" method="POST" class="inline" onsubmit="return confirm('削除しますか?');">
                            @csrf @method('DELETE')
                            <button class="text-slate-500 hover:text-rose-600">削除</button>
                        </form>
                    </div>
                </div>

                <div class="text-sm text-slate-500 mb-1">評価額</div>
                <div class="text-xl font-bold mb-3">¥{{ number_format($account->evaluation_amount) }}</div>

                <div class="text-xs text-slate-500 mb-1">年間投資枠消化</div>
                <div class="w-full h-2.5 bg-slate-100 rounded-full overflow-hidden mb-1">
                    <div class="h-full bg-emerald-600" style="width: {{ $usage }}%"></div>
                </div>
                <div class="text-xs text-slate-500 mb-3">
                    ¥{{ number_format($account->contributed_amount) }} / ¥{{ number_format($account->annual_limit) }}
                    ({{ $account->usage_rate }}%)
                </div>

                <div class="text-xs {{ $account->gain_loss >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                    含み損益: {{ $account->gain_loss >= 0 ? '+' : '' }}¥{{ number_format($account->gain_loss) }}
                </div>

                @if ($account->memo)
                    <p class="mt-2 text-xs text-slate-400">{{ $account->memo }}</p>
                @endif
            </div>
        @endforeach
    </div>
@endsection
