<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\MerchantCategoryRule;
use App\Services\MerchantCategoryGuesser;
use App\Support\DomainLimits;
use Illuminate\Http\Request;

/**
 * 店名 → カテゴリ の自動分類ルールの確認・追加・削除。
 */
class MerchantCategoryRuleController extends Controller
{
    public function index()
    {
        return view('merchant_rules.index', [
            'rules' => MerchantCategoryRule::with('category')
                ->orderByDesc('hit_count')
                ->orderBy('pattern')
                ->paginate(50),
            // 学習ルールは支出の推測にしか使わない。収入カテゴリを出すと、
            // 操作ミスで支出行に収入カテゴリが付くルールを作れてしまう
            'categories' => Category::where('type', 'expense')->orderBy('sort_order')->get(),
            'sourceLabels' => MerchantCategoryRule::SOURCE_LABELS,
        ]);
    }

    public function store(Request $request, MerchantCategoryGuesser $guesser)
    {
        $validated = $request->validate([
            'shop_name' => ['required', 'string', 'max:255'],
            // 学習ルールは支出の推測にしか使わない。収入カテゴリを選べると、
            // 以後のCSV取込で支出行に収入カテゴリが一括で付く
            'category_id' => ['required', DomainLimits::categoryRule('expense')],
        ]);

        // 同じ店名のルールが既にあると、カテゴリが黙って差し替わる。
        // 「追加しました」と言われて、別のルールが書き換わったことに気づけない
        $existing = MerchantCategoryRule::where('pattern', MerchantCategoryGuesser::normalize($validated['shop_name']))
            ->with('category')
            ->first();

        $guesser->remember(
            $validated['shop_name'],
            (int) $validated['category_id'],
            MerchantCategoryRule::SOURCE_MANUAL,
        );

        $status = $existing === null
            ? '自動分類ルールを追加しました。'
            : sprintf(
                '「%s」のルールを更新しました（%s → %s）。',
                $existing->display_name ?: $existing->pattern,
                $existing->category?->name ?? '未分類',
                Category::find((int) $validated['category_id'])?->name ?? '未分類',
            );

        return redirect()->route('merchant-rules.index')->with('status', $status);
    }

    public function update(Request $request, MerchantCategoryRule $merchantRule)
    {
        $validated = $request->validate([
            'category_id' => ['required', DomainLimits::categoryRule('expense')],
        ]);

        $merchantRule->update([
            'category_id' => (int) $validated['category_id'],
            'source' => MerchantCategoryRule::SOURCE_MANUAL,
        ]);

        return redirect()->route('merchant-rules.index')->with('status', '自動分類ルールを更新しました。');
    }

    public function destroy(MerchantCategoryRule $merchantRule)
    {
        $merchantRule->delete();

        return redirect()->route('merchant-rules.index')->with('status', '自動分類ルールを削除しました。');
    }
}
