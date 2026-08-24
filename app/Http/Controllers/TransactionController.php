<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\TransactionFilter;
use App\Support\DomainLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function __construct(private readonly TransactionFilter $filter)
    {
    }

    public function index(Request $request)
    {
        $filters = $this->filter->fromRequest($request);

        $query = $this->filter->apply(Transaction::query(), $filters);

        // 合計は絞り込み後の全件から集計する(ページ内だけの合計だと意味が無い)
        $summary = $this->filter->summarize(clone $query);

        $transactions = $this->filter
            ->applySort($query->with('category'), $filters['sort'] ?? null)
            ->paginate(30)
            ->withQueryString();

        $categories = Category::orderBy('type')->orderBy('sort_order')->get();

        return view('transactions.index', [
            'transactions' => $transactions,
            'categories' => $categories,
            'summary' => $summary,
            'filters' => $filters,
            'sorts' => TransactionFilter::SORTS,
            'exportParams' => array_filter($request->only(TransactionFilter::KEYS), fn ($v) => $v !== null && $v !== ''),
        ]);
    }
    /**
     * 一括変更で一度に触れる上限。
     *
     * 「絞り込み結果すべて」は絞り込みを忘れると全件になる。件数の突き合わせで
     * 事故はほぼ防げるが、それでも桁違いの件数は一度立ち止まらせる。
     */
    public const BULK_MAX = 2000;

    /** カテゴリ選択で「未分類に戻す」を表す値（空文字＝未選択と区別するため）。 */
    public const CLEAR_CATEGORY = 'none';

    /**
     * 選んだ取引、または絞り込み結果すべてのカテゴリをまとめて変更する。
     *
     * 自動分類ルールを直しても過去の取引は直らないので、後から揃えるための機能。
     * 「次回から自動でこのカテゴリにする」を選んだときだけ学習ルールにも反映する
     * （寄せ集めをまとめて『その他支出』にしたときに、良いルールを壊さないため）。
     *
     * 元に戻せない操作なので、事故を三重に防いでいる。
     *  1. カテゴリ未選択（空文字）は常に拒否する。かつて「未分類」が先頭の既定値だったので、
     *     絞り込み無しで一度ボタンを押すだけで全取引のカテゴリが消せてしまった。
     *  2. 「未分類に戻す」はチェックした取引にだけ許す（絞り込み結果すべてには使わせない）。
     *  3. 画面が表示していた件数を送らせ、サーバ側で数え直した件数と一致しなければ拒否する
     *     （CSV取込の確定と同じ手口。画面が古いまま押した事故を止める）。
     */
    public function bulkUpdate(Request $request)
    {
        // クロージャの中で引いたカテゴリを、あとの処理でも使い回す（2回引かない）
        $category = null;

        $validated = $request->validate([
            'scope' => ['required', 'in:selected,filtered'],
            'bulk_category_id' => [
                // bail が無いと、未選択のとき required と下のクロージャが同じ文を二度出す
                'bail',
                'required',
                function (string $attribute, mixed $value, callable $fail) use ($request, &$category) {
                    if ($value === self::CLEAR_CATEGORY) {
                        if ($request->input('scope') !== 'selected') {
                            $fail('「未分類に戻す」は、チェックした取引にだけ使えます。');
                        }

                        return;
                    }

                    if (is_scalar($value) && ctype_digit((string) $value)) {
                        $category = Category::find((int) $value);
                    }

                    if ($category === null) {
                        $fail('カテゴリを選んでください。');
                    }
                },
            ],
            'ids' => ['required_if:scope,selected', 'array', 'max:'.self::BULK_MAX],
            'ids.*' => ['integer'],
            // 画面が表示していた「絞り込み結果すべて」の件数
            'filtered_count' => ['required_if:scope,filtered', 'integer', 'min:0'],
            'learn' => ['nullable', 'boolean'],
        ], [
            'ids.required_if' => '変更する取引を1件以上選んでください。',
            'ids.max' => '一度に変更できるのは'.self::BULK_MAX.'件までです。絞り込んでから実行してください。',
            'bulk_category_id.required' => 'カテゴリを選んでください。',
            'filtered_count.required_if' => '画面の表示が古くなっています。一覧を再読み込みしてからやり直してください。',
        ], [
            'bulk_category_id' => 'カテゴリ',
        ]);

        // 絞り込み条件は一覧画面から hidden で引き継ぐ
        $filters = $this->filter->fromRequest($request);
        $query = $this->filter->apply(Transaction::query(), $filters);

        if ($validated['scope'] === 'selected') {
            $query->whereIn('id', $validated['ids']);
        }

        $expected = $validated['scope'] === 'selected'
            ? count(array_unique($validated['ids']))
            : (int) $validated['filtered_count'];

        if ($validated['bulk_category_id'] === self::CLEAR_CATEGORY) {
            $category = null;
        }

        $categoryId = $category?->id;
        $learn = $request->boolean('learn');

        // 件数だけ先に数える。上限超えの門前払いに、全件の id を積む必要は無い。
        $total = (clone $query)->count();

        // 画面が数えた件数と合わなければ、画面が古い（他タブで追加・削除した等）。
        // 元に戻せない操作なので、黙って多めに書き換えるより止める。
        // 0件になっていた場合も、まずここで理由を説明したほうが次の手が分かる。
        if ($total !== $expected) {
            return back()->withInput()->withErrors([
                'bulk' => '対象件数が画面の表示（'.number_format($expected).'件）と合いません（現在'
                    .number_format($total).'件）。一覧を再読み込みしてからやり直してください。',
            ]);
        }

        if ($total === 0) {
            return back()->withInput()->withErrors(['bulk' => '対象の取引がありませんでした。']);
        }

        if ($total > self::BULK_MAX) {
            return back()->withInput()->withErrors([
                'bulk' => '対象が'.number_format($total).'件あります。一度に変更できるのは'
                    .number_format(self::BULK_MAX).'件までなので、月や種別で絞り込んでから実行してください。',
            ]);
        }

        // 支出に収入カテゴリを付けると、月次集計も年次レポートも壊れる。
        // 一括変更は件数が多いぶん被害も大きいので、種別違いが1件でもあれば止める。
        if ($category !== null) {
            $mismatched = (clone $query)
                // NULL は '!=' に引っかからないので、明示的に拾う
                ->where(fn ($q) => $q->where('type', '!=', $category->type)->orWhereNull('type'))
                ->count();

            if ($mismatched > 0) {
                return back()->withInput()->withErrors([
                    'bulk' => '「'.$category->name.'」は'.($category->type === 'income' ? '収入' : '支出')
                        .'のカテゴリですが、対象に種別の違う取引が'.number_format($mismatched)
                        .'件あります。種別で絞り込んでから実行してください。',
                ]);
            }
        }

        DB::transaction(function () use ($query, $categoryId, $learn) {
            if ($learn) {
                // モデルイベントを通して TransactionObserver に学習させる。
                // 1件ずつ save() するので、ここだけは件数なりのクエリになる。
                (clone $query)->reorder()->chunkById(500, function ($transactions) use ($categoryId) {
                    foreach ($transactions as $transaction) {
                        $transaction->update(['category_id' => $categoryId]);
                    }
                });

                return;
            }

            // 学習させない場合はクエリ1本で更新する（イベントも発火しない）。
            // 「絞り込み結果すべて」でも id を配列に展開しない。展開すると件数なりの
            // プレースホルダになり、上限を上げたときに max_allowed_packet に当たる。
            // （scope=selected の側は ids の max:2000 で頭打ちにしてある）
            // Builder::update() は updated_at を自動で入れる。
            (clone $query)->reorder()->update(['category_id' => $categoryId]);
        });

        $message = number_format($total).'件のカテゴリを「'.($category?->name ?? '未分類').'」に変更しました。';

        if ($learn) {
            // 実際に学習されるかは店名ごとの事情（既に手で決めたルールがある等）で決まる。
            // 「反映しました」と言い切ると、書かれていないのに書いたことになる。
            $message .= $category?->type === 'expense'
                ? '（支出の店名は、次回から自動でこのカテゴリになります。手で決めたルールは上書きしません）'
                : '（自動分類は支出の店名だけが対象なので、ルールは変えていません）';
        }

        return redirect()
            ->route('transactions.index', array_filter($request->only(TransactionFilter::KEYS), fn ($v) => $v !== null && $v !== ''))
            ->with('status', $message);
    }

    public function create()
    {
        $categories = Category::orderBy('type')->orderBy('sort_order')->get();

        return view('transactions.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'transaction_date' => array_merge(['required'], DomainLimits::dateRules()),
            'type' => ['required', 'in:income,expense'],
            // 支出の行に収入カテゴリを付けられると、円グラフには出るのに
            // 固定／変動の内訳からは漏れる、という壊れ方をする
            'category_id' => ['nullable', DomainLimits::categoryRule($request->input('type'))],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:255'],
            'amount' => array_merge(['required'], DomainLimits::amountRules()),
        ]);

        Transaction::create($validated);

        return redirect()->route('transactions.index')->with('status', '取引を登録しました。');
    }

    public function edit(Transaction $transaction)
    {
        $categories = Category::orderBy('type')->orderBy('sort_order')->get();

        return view('transactions.edit', compact('transaction', 'categories'));
    }

    public function update(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'transaction_date' => array_merge(['required'], DomainLimits::dateRules()),
            'type' => ['required', 'in:income,expense'],
            'category_id' => ['nullable', DomainLimits::categoryRule($request->input('type'))],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:255'],
            'amount' => array_merge(['required'], DomainLimits::amountRules()),
        ]);

        $transaction->update($validated);

        return redirect()->route('transactions.index')->with('status', '取引を更新しました。');
    }

    public function destroy(Transaction $transaction)
    {
        $transaction->delete();

        return redirect()->route('transactions.index')->with('status', '取引を削除しました。');
    }
}
