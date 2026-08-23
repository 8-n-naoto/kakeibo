<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Services\BudgetService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
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

        Budget::updateOrCreate(
            ['category_id' => $validated['category_id'], 'month' => $validated['month']],
            $validated
        );

        return redirect()->route('budgets.index')->with('status', '予算を設定しました。');
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
        $validated = $this->validated($request);

        $budget->update($validated);

        return redirect()->route('budgets.index')->with('status', '予算を更新しました。');
    }

    public function destroy(Budget $budget)
    {
        $budget->delete();

        return redirect()->route('budgets.index')->with('status', '予算を削除しました。');
    }

    /**
     * @return array{category_id: ?int, month: ?string, amount: int, memo: ?string}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('type', 'expense')],
            // 空欄なら「毎月適用のデフォルト予算」
            'month' => ['nullable', 'date_format:Y-m'],
            'amount' => ['required', 'integer', 'min:0'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        $categoryId = $validated['category_id'] ?? null;
        $month = $validated['month'] ?? null;

        return [
            'category_id' => $categoryId !== null && $categoryId !== '' ? (int) $categoryId : null,
            'month' => ! empty($month)
                ? Carbon::createFromFormat('!Y-m', $month)->startOfMonth()->toDateString()
                : null,
            'amount' => (int) $validated['amount'],
            'memo' => $validated['memo'] ?? null,
        ];
    }

    private function expenseCategories()
    {
        return Category::expense()->orderBy('sort_order')->orderBy('id')->get();
    }

    private function resolveMonth(?string $month): Carbon
    {
        try {
            return $month
                ? Carbon::createFromFormat('!Y-m', $month)->startOfMonth()
                : Carbon::now()->startOfMonth();
        } catch (\Throwable) {
            return Carbon::now()->startOfMonth();
        }
    }
}
