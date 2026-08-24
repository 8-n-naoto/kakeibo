@php $recurring = $recurring ?? null; @endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div class="sm:col-span-2">
        <label class="block text-sm text-slate-600 mb-1">名前</label>
        <input type="text" name="name" value="{{ old('name', $recurring?->name) }}" maxlength="255" required
               placeholder="家賃 / Netflix / 生命保険 など"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">種別</label>
        <select name="type" data-type-control class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            <option value="expense" @selected(old('type', $recurring?->type ?? 'expense') === 'expense')>支出</option>
            <option value="income" @selected(old('type', $recurring?->type ?? 'expense') === 'income')>収入</option>
        </select>
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">カテゴリ</label>
        <select name="category_id" data-category-select
                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            @include('partials.category-options', [
                'categories' => $categories,
                'selectedCategoryId' => old('category_id', $recurring?->category_id),
            ])
        </select>
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">金額(円)</label>
        <input type="number" name="amount" min="0" required value="{{ old('amount', $recurring?->amount) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">毎月の計上日</label>
        <input type="number" name="day_of_month" min="1" max="31" required
               value="{{ old('day_of_month', $recurring?->day_of_month ?? 1) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        <p class="text-xs text-slate-400 mt-1">31 を指定した月に31日が無い場合は、その月の末日に計上されます。</p>
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">店名・支払先（任意）</label>
        <input type="text" name="shop_name" value="{{ old('shop_name', $recurring?->shop_name) }}" maxlength="255"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">メモ（任意）</label>
        <input type="text" name="memo" value="{{ old('memo', $recurring?->memo) }}" maxlength="255"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        <p class="text-xs text-slate-400 mt-1">空欄のときは名前がメモとして入ります。</p>
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">開始日（任意）</label>
        <input type="date" name="starts_on"
               value="{{ old('starts_on', $recurring?->starts_on?->format('Y-m-d')) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
    </div>

    <div>
        <label class="block text-sm text-slate-600 mb-1">終了日（任意）</label>
        <input type="date" name="ends_on"
               value="{{ old('ends_on', $recurring?->ends_on?->format('Y-m-d')) }}"
               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
        <p class="text-xs text-slate-400 mt-1">解約した月まで入れておくと、それ以降は計上されません。</p>
    </div>

    <div class="sm:col-span-2">
        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300"
                   @checked(old('is_active', $recurring?->is_active ?? true))>
            有効にする（チェックを外すと計上されません）
        </label>
    </div>
</div>
