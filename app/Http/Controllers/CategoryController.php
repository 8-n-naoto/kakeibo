<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\MerchantCategoryRule;
use App\Support\CategoryIdIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use LogicException;

class CategoryController extends Controller
{
    public function index()
    {
        // 削除は取り消せないうえ、予算と学習ルールは**一緒に消える**。
        // 何が巻き添えになるかを、押す前に数字で見せる。
        $categories = Category::withImpactCounts()
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('categories.index', [
            'expenseCategories' => $categories->where('type', 'expense'),
            'incomeCategories' => $categories->where('type', 'income'),
            'natures' => Category::NATURES,
        ]);
    }

    public function create()
    {
        return view('categories.create', ['natures' => Category::NATURES]);
    }

    public function store(Request $request)
    {
        Category::create($this->validated($request));

        // ID一覧は1リクエストの間キャッシュしている。作った直後に検証すると
        // 「存在しないカテゴリ」になるので捨てる
        app(CategoryIdIndex::class)->forget();

        return redirect()->route('categories.index')->with('status', 'カテゴリを追加しました。');
    }

    public function edit(Category $category)
    {
        return view('categories.edit', [
            'category' => $category,
            'natures' => Category::NATURES,
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $category->update($this->validated($request, $category));

        app(CategoryIdIndex::class)->forget();

        return redirect()->route('categories.index')->with('status', 'カテゴリを更新しました。');
    }

    public function destroy(Request $request, Category $category)
    {
        $expected = $request->input('expected_impact');
        $name = $category->name;

        // 数えるのと消すのを1つのトランザクションにまとめる。
        // 間に子テーブルへ挿入されるのまでは止められないが、窓を最小にする。
        $impact = DB::transaction(function () use ($category) {
            $category->newQuery()->whereKey($category->getKey())->lockForUpdate()->first();

            return self::impactOf($category);
        });

        // 画面が表示していた件数と合わなければ止める。
        // 予算と手で登録したルールは一緒に消えて取り消せないので、
        // 「開いたときは0件だったのに、実は数年分あった」を起こさない。
        // 突き合わせるのは**戻せないもの**だけ。取引・定期支出は未分類になるだけ、
        // 自動学習ルールは次の取込で覚え直せるので、そこがずれても止める理由が無い
        // （止めると、取込が走っただけで何度読み直しても削除できなくなる）。
        if (! is_scalar($expected) || (string) $expected !== self::impactSignature($impact)) {
            return redirect()->route('categories.index')->withErrors([
                'category' => sprintf(
                    '画面の表示が古くなっています。いま「%s」に紐づいているのは、'
                        .'予算%s件・手で登録した自動分類ルール%s件です。'
                        .'一覧を読み直して、この件数でよければもう一度削除してください。',
                    $name,
                    number_format($impact['budgets']),
                    number_format($impact['manual_rules']),
                ),
            ]);
        }

        DB::transaction(function () use ($category) {
            $category->delete();
        });

        app(CategoryIdIndex::class)->forget();

        $message = '「'.$name.'」を削除しました。';
        $message .= $impact['transactions'] > 0
            ? number_format($impact['transactions']).'件の取引は「未分類」になりました。'
            : '';
        $message .= $impact['recurring_transactions'] > 0
            ? number_format($impact['recurring_transactions']).'件の定期支出は「未分類」になりました。'
            : '';
        $message .= $impact['budgets'] > 0
            ? number_format($impact['budgets']).'件の予算を削除しました。'
            : '';
        $message .= $impact['merchant_category_rules'] > 0
            ? number_format($impact['merchant_category_rules']).'件の自動分類ルール'
                .($impact['manual_rules'] > 0
                    ? '（うち手で登録したもの'.number_format($impact['manual_rules']).'件）'
                    : '')
                .'を削除しました。'
            : '';

        return redirect()->route('categories.index')->with('status', $message);
    }

    /**
     * このカテゴリを消したときに巻き添えになるものの件数。
     *
     * @return array{transactions: int, recurring_transactions: int, budgets: int, merchant_category_rules: int, manual_rules: int}
     */
    public static function impactOf(Category $category): array
    {
        return [
            // nullOnDelete（未分類になる）
            'transactions' => $category->transactions()->count(),
            'recurring_transactions' => $category->recurringTransactions()->count(),
            // cascadeOnDelete（一緒に消える）
            'budgets' => $category->budgets()->count(),
            'merchant_category_rules' => $category->merchantCategoryRules()->count(),
            // 学習ルールは次の取込でまた覚え直せるが、手で登録したルールは戻らない
            'manual_rules' => $category->merchantCategoryRules()
                ->where('source', MerchantCategoryRule::SOURCE_MANUAL)
                ->count(),
        ];
    }

    /**
     * 一覧の withCount で読んだ件数から、同じ形の配列を作る。
     *
     * ビュー側でキーを手書きすると、リレーションが増えたときに
     * 片方だけ更新されて「必ず削除が拒否される」状態になる。
     *
     * @return array{transactions: int, recurring_transactions: int, budgets: int, merchant_category_rules: int, manual_rules: int}
     */
    public static function impactFromCounts(Category $category): array
    {
        if (! array_key_exists('manual_rules_count', $category->getAttributes())) {
            throw new LogicException(
                'Category::withImpactCounts() を通していないカテゴリです（件数が全部0になり、削除が必ず拒否されます）',
            );
        }

        return [
            'transactions' => (int) $category->transactions_count,
            'recurring_transactions' => (int) $category->recurring_transactions_count,
            'budgets' => (int) $category->budgets_count,
            'merchant_category_rules' => (int) $category->merchant_category_rules_count,
            'manual_rules' => (int) $category->manual_rules_count,
        ];
    }

    /**
     * 画面が表示した件数と、サーバが数え直した件数を突き合わせるための文字列。
     *
     * **戻せないものだけ**を対象にする。
     *  - 取引・定期支出 … 未分類になるだけ
     *  - 自動学習ルール … 取引を1件保存するたびに増える。次の取込で覚え直せる
     * この2つを入れると、取込が走っただけで何度読み直しても削除できなくなる。
     *
     * 件数だけなので「1件消えて1件増えた」は見抜けない。単一ユーザーのアプリなので
     * そこまでは見ない（厳密にやるなら対象行のIDを並べてハッシュを取る）。
     *
     * @param  array{budgets: int, manual_rules: int}  $impact
     */
    public static function impactSignature(array $impact): string
    {
        return implode('-', [
            $impact['budgets'],
            $impact['manual_rules'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Category $category = null): array
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->ignore($category?->id),
            ],
            'type' => [
                'required',
                'in:income,expense',
                // 使われているカテゴリの種別を変えると、そのカテゴリを指している
                // 取引・定期支出・学習ルール・予算がまとめて「種別違い」になる。
                // 画面には何も出ないまま、次に保存しようとしたときだけ弾かれる。
                function (string $attribute, mixed $value, callable $fail) use ($category) {
                    if ($category === null || $value === $category->type) {
                        return;
                    }

                    $impact = self::impactOf($category);
                    $used = $impact['transactions']
                        + $impact['recurring_transactions']
                        + $impact['budgets']
                        + $impact['merchant_category_rules'];

                    if ($used > 0) {
                        $fail(sprintf(
                            'このカテゴリは%s件の取引・予算・定期支出・自動分類ルールで使われているため、'
                                .'種別（収入／支出）は変えられません。先に付け替えてください。',
                            number_format($used),
                        ));
                    }
                },
            ],
            'expense_nature' => ['nullable', Rule::in(array_keys(Category::NATURES))],
            'counts_as_food' => ['nullable', 'boolean'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        // 収入カテゴリに固定費/変動費の区分は持たせない
        $validated['expense_nature'] = $validated['type'] === 'expense'
            ? ($validated['expense_nature'] ?? Category::NATURE_VARIABLE)
            : null;

        // チェックボックスは未チェックだと送信されないので明示的に false を入れる
        $validated['counts_as_food'] = $validated['type'] === 'expense'
            && $request->boolean('counts_as_food');

        return $validated;
    }
}
