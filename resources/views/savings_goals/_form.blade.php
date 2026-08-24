@php
    $g = $goal ?? null;
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div class="sm:col-span-2">
        <label class="block text-sm text-slate-600 mb-1">目標名</label>
        <input type="text" name="name" required value="{{ old('name', $g->name ?? '') }}"
               placeholder="例: 旅行資金、住宅頭金、緊急予備資金"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">目標金額(円)</label>
        <input type="number" name="target_amount" min="1" required value="{{ old('target_amount', $g->target_amount ?? '') }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">現在の貯蓄額(円)</label>
        <input type="number" name="current_amount" min="0" required value="{{ old('current_amount', $g->current_amount ?? 0) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">達成期限(任意)</label>
        <input type="date" name="target_date" value="{{ old('target_date', $g?->target_date?->format('Y-m-d')) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">メモ</label>
        <input type="text" name="memo" value="{{ old('memo', $g->memo ?? '') }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
</div>
