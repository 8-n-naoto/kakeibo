@php
    $budget = $budget ?? null;
    $selectedCategory = old('category_id', $budget?->category_id);
    $selectedMonth = old('month', $budget?->month?->format('Y-m') ?? ($defaultMonth ?? ''));
@endphp

<div class="space-y-4">
    <div>
        <label class="block text-sm text-slate-600 mb-1">対象</label>
        <select name="category_id" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            <option value="" @selected($selectedCategory === null || $selectedCategory === '')>支出全体</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((string) $selectedCategory === (string) $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        <p class="text-xs text-slate-400 mt-1">「支出全体」を選ぶと、その月の支出合計に対する予算になります。</p>
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">対象月</label>
        <input type="month" name="month" value="{{ $selectedMonth }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        <p class="text-xs text-slate-400 mt-1">空欄にすると「毎月適用されるデフォルト予算」になります。月を指定した予算があればそちらが優先されます。</p>
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">予算額(円)</label>
        <input type="number" name="amount" value="{{ old('amount', $budget?->amount ?? 0) }}" min="0" step="1" required
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">メモ</label>
        <input type="text" name="memo" value="{{ old('memo', $budget?->memo) }}" maxlength="255"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
</div>
