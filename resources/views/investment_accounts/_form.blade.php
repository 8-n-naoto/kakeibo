@php
    $a = $account ?? null;
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm text-slate-600 mb-1">口座種別</label>
        <select name="account_type" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" {{ $a ? 'disabled' : '' }}>
            @foreach ($accountTypes as $value => $label)
                <option value="{{ $value }}" @selected(old('account_type', $a->account_type ?? null) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @if ($a)
            <input type="hidden" name="account_type" value="{{ $a->account_type }}">
            <p class="text-xs text-slate-400 mt-1">口座種別は編集できません(年度・種別の組み合わせで一意)。</p>
        @endif
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">年度</label>
        <input type="number" name="fiscal_year" required value="{{ old('fiscal_year', $a->fiscal_year ?? now()->year) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">年間投資枠上限(円)</label>
        <input type="number" name="annual_limit" min="0" required value="{{ old('annual_limit', $a->annual_limit ?? '') }}"
               placeholder="例: 成長投資枠2,400,000 / つみたて投資枠1,200,000"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">年間拠出・投資済み額(円)</label>
        <input type="number" name="contributed_amount" min="0" required value="{{ old('contributed_amount', $a->contributed_amount ?? 0) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">評価額(円)</label>
        <input type="number" name="evaluation_amount" min="0" required value="{{ old('evaluation_amount', $a->evaluation_amount ?? 0) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm text-slate-600 mb-1">メモ</label>
        <input type="text" name="memo" value="{{ old('memo', $a->memo ?? '') }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
</div>
