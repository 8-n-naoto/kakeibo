@php $category = $category ?? null; @endphp

<div class="space-y-4">
    <div>
        <label class="block text-sm text-slate-600 mb-1">カテゴリ名</label>
        <input type="text" name="name" value="{{ old('name', $category?->name) }}" maxlength="255" required
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">種別</label>
        <select name="type" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            <option value="expense" @selected(old('type', $category?->type) === 'expense')>支出</option>
            <option value="income" @selected(old('type', $category?->type) === 'income')>収入</option>
        </select>
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">固定費 / 変動費</label>
        <select name="expense_nature" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            @foreach ($natures as $value => $label)
                <option value="{{ $value }}" @selected(old('expense_nature', $category?->expense_nature ?? 'variable') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="text-xs text-slate-400 mt-1">支出カテゴリのみ有効です。家賃・通信費など毎月ほぼ一定のものを「固定費」にしてください。</p>
    </div>

    <div>
        <label class="flex items-start gap-2 text-sm text-slate-600">
            <input type="hidden" name="counts_as_food" value="0">
            <input type="checkbox" name="counts_as_food" value="1" class="mt-0.5 rounded border-slate-300"
                   @checked(old('counts_as_food', $category?->counts_as_food ?? false))>
            <span>
                エンゲル係数の「食料費」に含める
                <span class="block text-xs text-slate-400 mt-0.5">
                    支出カテゴリのみ有効です。総務省の家計調査では外食も食料に含めます。
                </span>
            </span>
        </label>
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">グラフの色</label>
        <input type="color" name="color" value="{{ old('color', $category?->color ?? '#94a3b8') }}"
               class="h-10 w-20 border border-slate-300 rounded-lg px-1 py-1">
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">表示順</label>
        <input type="number" name="sort_order" value="{{ old('sort_order', $category?->sort_order ?? 50) }}" min="0" max="999" required
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>
</div>
