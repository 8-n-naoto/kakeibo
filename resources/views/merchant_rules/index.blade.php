@extends('layouts.app')

@section('title', '自動分類ルール | 家計簿アプリ')

@section('content')
    <h1 class="text-xl font-bold mb-2">自動分類ルール（店名 → カテゴリ）</h1>
    <p class="text-sm text-slate-500 mb-6">
        取引を登録・更新するたびに「この店名はこのカテゴリ」を自動で覚えます。
        覚えたルールは次回のCSV取込とレシート解析で最優先に適用されるので、同じ店を何度も直す必要がなくなります。
    </p>

    <form method="POST" action="{{ route('merchant-rules.store') }}"
          class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-6">
        @csrf
        <div class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-3 items-end">
            <div>
                <label class="block text-xs text-slate-500 mb-1">店名（またはその一部）</label>
                <input type="text" name="shop_name" value="{{ old('shop_name') }}" required maxlength="255"
                       placeholder="セブン-イレブン"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">カテゴリ</label>
                {{-- 学習ルールは支出の推測にしか使わないので、支出カテゴリしか出さない --}}
                <select name="category_id" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">選択してください</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected(old('category_id') == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="bg-emerald-600 text-white text-sm px-5 py-2 rounded-lg hover:bg-emerald-700">ルールを追加</button>
        </div>
        <p class="text-xs text-slate-400 mt-2">
            店名の一部だけでも登録できます（例:「セブン」→ 食費）。完全一致のルールが優先され、次に一致した文字列が長いルールが使われます。
        </p>
    </form>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50">
                <tr class="text-left text-slate-500 border-b border-slate-200">
                    <th class="py-2 px-3">店名パターン</th>
                    <th class="px-3">カテゴリ</th>
                    <th class="px-3">登録元</th>
                    <th class="px-3 text-right">適用回数</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rules as $rule)
                    <tr class="border-b border-slate-100">
                        <td class="py-2 px-3">
                            {{ $rule->display_name ?: $rule->pattern }}
                            @if ($rule->display_name && $rule->display_name !== $rule->pattern)
                                <span class="block text-xs text-slate-400">照合値: {{ $rule->pattern }}</span>
                            @endif
                        </td>
                        <td class="px-3">
                            <form method="POST" action="{{ route('merchant-rules.update', $rule) }}" class="flex gap-2 items-center">
                                @csrf @method('PUT')
                                {{-- カテゴリが収入に変えられた／消えた古いルールは、一覧の候補に無い。
                                     空欄が無いとブラウザが先頭を勝手に選び、保存を押した瞬間に
                                     身に覚えのないカテゴリへ付け替わる。 --}}
                                @php $ruleCategoryListed = $categories->contains('id', $rule->category_id); @endphp
                                <select name="category_id" required class="border border-slate-300 rounded-lg px-2 py-1 text-xs {{ $ruleCategoryListed ? '' : 'border-rose-400' }}">
                                    @unless ($ruleCategoryListed)
                                        <option value="" selected>（支出カテゴリを選び直してください）</option>
                                    @endunless
                                    @foreach ($categories as $c)
                                        <option value="{{ $c->id }}" @selected($rule->category_id == $c->id)>{{ $c->name }}</option>
                                    @endforeach
                                </select>
                                <button class="text-xs text-slate-500 hover:text-emerald-600">保存</button>
                                @unless ($ruleCategoryListed)
                                    <span class="text-xs text-rose-600">いまは「{{ $rule->category?->name ?? '削除されたカテゴリ' }}」（支出ではありません）</span>
                                @endunless
                            </form>
                        </td>
                        <td class="px-3 text-xs text-slate-500">{{ $sourceLabels[$rule->source] ?? $rule->source }}</td>
                        <td class="px-3 text-right text-slate-500">{{ $rule->hit_count }}</td>
                        <td class="px-3 text-right whitespace-nowrap">
                            <form action="{{ route('merchant-rules.destroy', $rule) }}" method="POST" class="inline"
                                  onsubmit="return confirm('このルールを削除しますか?');">
                                @csrf @method('DELETE')
                                <button class="text-slate-500 hover:text-rose-600">削除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-slate-400">まだルールがありません。取引を登録すると自動で覚えます。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rules->links() }}</div>
@endsection
