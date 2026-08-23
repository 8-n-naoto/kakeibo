@php
    $t = $transaction ?? null;
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm text-slate-600 mb-1">日付</label>
        <input type="date" name="transaction_date" required
               value="{{ old('transaction_date', $t?->transaction_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">種別</label>
        <select name="type" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            <option value="expense" @selected(old('type', $t->type ?? 'expense') === 'expense')>支出</option>
            <option value="income" @selected(old('type', $t->type ?? 'expense') === 'income')>収入</option>
        </select>
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">カテゴリ</label>
        <select name="category_id" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            <option value="">未分類</option>
            @foreach ($categories as $c)
                <option value="{{ $c->id }}" @selected(old('category_id', $t->category_id ?? null) == $c->id)>
                    {{ $c->type === 'income' ? '収入' : '支出' }} / {{ $c->name }}
                </option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">金額(円)</label>
        <input type="number" name="amount" min="0" required
               value="{{ old('amount', $t->amount ?? '') }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">店名</label>
        <input type="text" name="shop_name" value="{{ old('shop_name', $t->shop_name ?? '') }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
    <div>
        <label class="block text-sm text-slate-600 mb-1">メモ</label>
        <input type="text" name="memo" value="{{ old('memo', $t->memo ?? '') }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
</div>
