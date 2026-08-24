@extends('layouts.app')

@section('title', 'CSV取込の履歴 | 家計簿アプリ')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-bold">CSV取込の履歴</h1>
            <p class="text-sm text-slate-500 mt-1">間違えて取り込んだときは、その取込で入った取引をまとめて取り消せます。</p>
        </div>
        <a href="{{ route('imports.create') }}" class="text-sm text-slate-500 hover:text-slate-700">CSVを取り込む</a>
    </div>

    @if ($batches->isEmpty())
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 text-sm text-slate-500">
            まだ取込の記録がありません。
        </div>
    @else
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-500 text-xs">
                    <tr class="text-left border-b border-slate-200">
                        <th class="py-2 px-3">取り込んだ日時</th>
                        <th class="px-3">ファイル</th>
                        <th class="px-3">対象期間</th>
                        <th class="px-3 text-right">件数</th>
                        <th class="px-3 text-right">差引</th>
                        <th class="px-3">いまの状態</th>
                        <th class="px-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($batches as $batch)
                        <tr>
                            <td class="py-2 px-3 whitespace-nowrap">{{ $batch->created_at?->format('Y/n/j H:i') }}</td>
                            <td class="px-3 text-slate-600">{{ $batch->file_name ?? '—' }}</td>
                            <td class="px-3 whitespace-nowrap text-slate-600">{{ $batch->period_label }}</td>
                            <td class="px-3 text-right">{{ number_format($batch->row_count) }}件</td>
                            <td class="px-3 text-right">¥{{ number_format($batch->total_amount) }}</td>
                            <td class="px-3 text-xs">
                                @if ($batch->transactions_count === 0)
                                    <span class="text-slate-400">取り消し済み</span>
                                @else
                                    <span class="text-slate-600">{{ number_format($batch->transactions_count) }}件が残っています</span>
                                    @if ($batch->edited_count > 0)
                                        <span class="block text-amber-600">うち{{ $batch->edited_count }}件は取込後に編集済み（取り消しても残ります）</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-3 text-right">
                                @if ($batch->revertable_count > 0)
                                    <form method="POST" action="{{ route('imports.batches.destroy', $batch) }}"
                                          onsubmit="return confirm('この取込で入った{{ $batch->revertable_count }}件の取引を削除します。元に戻せません。よろしいですか？');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-rose-600 hover:text-rose-700 text-xs whitespace-nowrap">
                                            {{ number_format($batch->revertable_count) }}件を取り消す
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-xs text-slate-500 mt-4">
            取り込んだあとに手で直した取引は、取り消しの巻き添えでは消しません。直したということは、その行にはもう人の判断が入っているためです。
        </p>
    @endif
@endsection
