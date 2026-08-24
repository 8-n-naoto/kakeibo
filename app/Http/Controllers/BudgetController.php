<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesMonth;
use App\Models\Budget;
use App\Models\Category;
use App\Services\BudgetService;
use App\Support\DomainLimits;
use App\Support\MonthParser;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    use ResolvesMonth;

    public function __construct(private readonly BudgetService $budgetService)
    {
    }

    public function index(Request $request)
    {
        $month = $this->resolveMonth($request->input('month'));

        $summary = $this->budgetService->summary($month);

        $budgets = Budget::with('category')
            ->orderByRaw('month is null desc')
            ->orderByDesc('month')
            ->orderByRaw('category_id is null desc')
            ->get();

        return view('budgets.index', [
            'month' => $month,
            'summary' => $summary,
            'budgets' => $budgets,
            'statusLabels' => BudgetService::STATUS_LABELS,
        ]);
    }

    public function create(Request $request)
    {
        return view('budgets.create', [
            'categories' => $this->expenseCategories(),
            'defaultMonth' => $this->resolveMonth($request->input('month'))->format('Y-m'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        // 同じカテゴリ・同じ月の予算は、メモごと黙って置き換わっていた。
        // 「先月いくらにしていたか」を確認せずに上書きしてしまうので、明示的に選ばせる。
        $existing = $this->sameBudget($validated)->first();

        if ($existing !== null && ! $request->boolean('overwrite')) {
            return back(fallback: route('budgets.create'))->withInput()->withErrors([
                'amount' => sprintf(
                    '%sの予算はすでに登録されています（現在 ¥%s%s）。'
                        .'置き換えるなら「すでにある予算は上書きする」にチェックを入れてください。',
                    $this->budgetLabel($existing),
                    number_format($existing->amount),
                    $existing->memo ? '／メモ「'.$existing->memo.'」' : '',
                ),
            ]);
        }

        Budget::updateOrCreate(
            ['category_id' => $validated['category_id'], 'month' => $validated['month']],
            $validated
        );

        return redirect()->route('budgets.index')->with(
            'status',
            $existing !== null ? '予算を上書きしました。' : '予算を設定しました。',
        );
    }

    /** エラー文に出す「◯月の食費」のような呼び名 */
    private function budgetLabel(Budget $budget): string
    {
        $month = $budget->month?->format('Y年n月') ?? '毎月';
        $category = $budget->category?->name ?? '支出全体';

        return $month.'の'.$category;
    }

    public function edit(Budget $budget)
    {
        return view('budgets.edit', [
            'budget' => $budget,
            'categories' => $this->expenseCategories(),
        ]);
    }

    public function update(Request $request, Budget $budget)
    {
        $validated = $this->validated($request, $budget);

        // (category_id, month) に unique 制約は張れない（NULL を含む行は
        // MySQL でも SQLite でも重複できてしまう）。ここで自前で見る。
        // 二重に登録されると BudgetService の ->first() がどちらを拾うか分からず、
        // 「直したはずの予算が反映されない」という追えない状態になる。
        $conflict = $this->sameBudget($validated)->whereKeyNot($budget->getKey())->first();

        if ($conflict !== null) {
            return back(fallback: route('budgets.edit', $budget))->withInput()->withErrors([
                'amount' => sprintf(
                    '%sの予算はすでに別に登録されています（現在 ¥%s）。先にそちらを削除するか、別の対象・月を選んでください。',
                    $this->budgetLabel($conflict),
                    number_format($conflict->amount),
                ),
            ]);
        }

        $budget->update($validated);

        return redirect()->route('budgets.index')->with('status', '予算を更新しました。');
    }

    /**
     * 同じ対象（カテゴリ）・同じ月の予算を引くクエリ。
     *
     * @param  array{category_id: ?int, month: ?string, amount: int, memo: ?string}  $validated
     * @return \Illuminate\Database\Eloquent\Builder<Budget>
     */
    private function sameBudget(array $validated)
    {
        return Budget::query()
            ->where('category_id', $validated['category_id'])
            // month は Y-m-d の文字列。date() を噛ませるとインデックスが効かない
            ->where('month', $validated['month']);
    }

    public function destroy(Budget $budget)
    {
        $budget->delete();

        return redirect()->route('budgets.index')->with('status', '予算を削除しました。');
    }

    /**
     * @return array{category_id: ?int, month: ?string, amount: int, memo: ?string}
     */
    private function validated(Request $request, ?Budget $current = null): array
    {
        // すでに登録されている月は、範囲外でもそのまま編集・保存できるようにする。
        // 範囲を後から狭めたせいで既存の行が直せなくなる、という状態を作らない。
        $existing = $current?->month?->format('Y-m');

        $validated = $request->validate([
            'category_id' => ['nullable', DomainLimits::categoryRule('expense')],
            // 空欄なら「毎月適用のデフォルト予算」
            'month' => ['bail', 'nullable', 'date_format:Y-m', function (string $attribute, mixed $value, callable $fail) use ($existing): void {
                // date_format:Y-m は "1999-01" や "3000-01" を通してしまうので範囲も見る
                if (blank($value) || $value === $existing) {
                    return;
                }

                if (MonthParser::parse($value) === null) {
                    $fail('対象月が正しくありません。');
                }
            }],
            'amount' => array_merge(['required'], DomainLimits::amountRules()),
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        $categoryId = $validated['category_id'] ?? null;
        $month = $validated['month'] ?? null;

        return [
            'category_id' => $categoryId !== null && $categoryId !== '' ? (int) $categoryId : null,
            // 範囲外でも既存の値ならそのまま通す。MonthParser が null を返す場合は元の値を使う
            'month' => ! empty($month)
                ? (MonthParser::parse($month)?->toDateString() ?? $current?->month?->toDateString())
                : null,
            'amount' => (int) $validated['amount'],
            'memo' => $validated['memo'] ?? null,
        ];
    }

    private function expenseCategories()
    {
        return Category::expense()->orderBy('sort_order')->orderBy('id')->get();
    }
}
