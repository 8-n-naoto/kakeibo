@php
    $s = $snapshot ?? null;
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm text-slate-600 mb-1">対象月</label>
        <input type="month" name="snapshot_date" required
               value="{{ old('snapshot_date', $s?->snapshot_date?->format('Y-m') ?? now()->format('Y-m')) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div></div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">現金・預金(円)</label>
        <input type="number" name="cash_amount" min="0" required
               value="{{ old('cash_amount', $s->cash_amount ?? ($latest->cash_amount ?? 0)) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">NISA評価額(円)</label>
        <input type="number" name="nisa_amount" min="0" required
               value="{{ old('nisa_amount', $s->nisa_amount ?? ($latest->nisa_amount ?? 0)) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">iDeCo評価額(円)</label>
        <input type="number" name="ideco_amount" min="0" required
               value="{{ old('ideco_amount', $s->ideco_amount ?? ($latest->ideco_amount ?? 0)) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">その他投資(円)</label>
        <input type="number" name="other_investment_amount" min="0" required
               value="{{ old('other_investment_amount', $s->other_investment_amount ?? ($latest->other_investment_amount ?? 0)) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm text-slate-600 mb-1">メモ</label>
        <input type="text" name="memo" value="{{ old('memo', $s->memo ?? '') }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
</div>
<p class="text-xs text-slate-400 mt-3">※ 同じ月で登録すると内容が上書きされます(1ヶ月1件のスナップショット)。</p>
