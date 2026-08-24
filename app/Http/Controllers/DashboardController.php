<?php

namespace App\Http\Controllers;

use App\Models\AssetSnapshot;
use App\Models\Category;
use App\Models\InvestmentAccount;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Services\BudgetService;
use App\Services\MonthlyReportService;
use App\Services\RecurringTransactionService;
use App\Http\Controllers\Concerns\ResolvesMonth;
use App\Services\SpendingAnomalyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    use ResolvesMonth;

    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly MonthlyReportService $reportService,
        private readonly RecurringTransactionService $recurringService,
        private readonly SpendingAnomalyService $anomalyService,
    ) {
    }

    public function index(Request $request)
    {
        $currentMonth = $this->resolveMonth($request->input('month'));

        $monthlyTransactions = Transaction::with('category')
            ->whereBetween('transaction_date', [
                $currentMonth->copy()->startOfMonth()->toDateString(),
                $currentMonth->copy()->endOfMonth()->toDateString(),
            ])
            ->orderByDesc('transaction_date')
            ->get();

        $income = (int) $monthlyTransactions->where('type', 'income')->sum('amount');
        $expense = (int) $monthlyTransactions->where('type', 'expense')->sum('amount');
        $balance = $income - $expense;

        // カテゴリ別支出(円グラフ用)
        $byCategory = $monthlyTransactions
            ->where('type', 'expense')
            ->groupBy(fn ($t) => $t->category?->name ?? '未分類')
            ->map(function ($items, $name) {
                return [
                    'name' => $name,
                    'amount' => (int) $items->sum('amount'),
                    'color' => $items->first()->category?->color ?? '#94a3b8',
                ];
            })
            ->sortByDesc('amount')
            ->values();

        // 直近12ヶ月の収支推移(折れ線/棒グラフ用)
        $trend = $this->buildTrend($currentMonth);

        // エンゲル係数(今月): 食料費 ÷ 消費支出(今月の支出合計)
        // 対象カテゴリはカテゴリ管理画面のチェックで決める(カテゴリ名のハードコードをやめた)
        $foodCategoryIds = Category::countsAsFood()->pluck('id');
        $foodCategoriesConfigured = $foodCategoryIds->isNotEmpty();

        $foodExpense = $monthlyTransactions
            ->where('type', 'expense')
            ->filter(fn ($t) => $t->category_id !== null && $foodCategoryIds->contains($t->category_id))
            ->sum('amount');

        $engelCoefficient = ($foodCategoriesConfigured && $expense > 0)
            ? round(($foodExpense / $expense) * 100, 1)
            : null;

        // 資産推移(直近12ヶ月のスナップショット)
        $assetSnapshots = AssetSnapshot::where('snapshot_date', '>=', $currentMonth->copy()->subMonths(11)->startOfMonth()->toDateString())
            ->where('snapshot_date', '<=', $currentMonth->copy()->endOfMonth()->toDateString())
            ->orderBy('snapshot_date')
            ->get();

        $netWorthTrend = $assetSnapshots->map(fn ($s) => [
            'label' => $s->snapshot_date->format('Y/m'),
            'total' => $s->total_amount,
            'cash' => $s->cash_amount,
            'nisa' => $s->nisa_amount,
            'ideco' => $s->ideco_amount,
            'other' => $s->other_investment_amount,
        ])->values();

        $latestSnapshot = $assetSnapshots->last();
        $previousSnapshot = $assetSnapshots->count() > 1 ? $assetSnapshots[$assetSnapshots->count() - 2] : null;
        $netWorthChange = ($latestSnapshot && $previousSnapshot)
            ? $latestSnapshot->total_amount - $previousSnapshot->total_amount
            : null;

        // 貯蓄目標
        $savingsGoals = SavingsGoal::orderByRaw('target_date IS NULL, target_date asc')->take(3)->get();

        // NISA/iDeCo(今年度分)
        $investmentAccounts = InvestmentAccount::where('fiscal_year', now()->year)
            ->orderBy('account_type')
            ->get();

        $categories = Category::orderBy('type')->orderBy('sort_order')->get();

        // Phase3: 予算アラート / 固定費・変動費 / 前年同月比
        // 未計上の定期支出（家賃・サブスクなど）
        $pendingRecurrings = $this->recurringService->pendingFor($currentMonth);

        // 予算を決めていないカテゴリの急増や単発の高額支出
        $anomalies = $this->anomalyService->detect($currentMonth);

        $budgetSummary = $this->budgetService->summary($currentMonth);
        $fixedVariable = $this->reportService->fixedVariableBreakdown($currentMonth);
        $yearOverYear = $this->reportService->yearOverYear($currentMonth);

        return view('dashboard.index', [
            'currentMonth' => $currentMonth,
            'income' => $income,
            'expense' => $expense,
            'balance' => $balance,
            'byCategory' => $byCategory,
            'trend' => $trend,
            'transactions' => $monthlyTransactions->take(10),
            'categories' => $categories,
            'engelCoefficient' => $engelCoefficient,
            'foodCategoriesConfigured' => $foodCategoriesConfigured,
            'foodExpense' => (int) $foodExpense,
            'netWorthTrend' => $netWorthTrend,
            'latestSnapshot' => $latestSnapshot,
            'netWorthChange' => $netWorthChange,
            'savingsGoals' => $savingsGoals,
            'investmentAccounts' => $investmentAccounts,
            'pendingRecurrings' => $pendingRecurrings,
            'anomalies' => $anomalies,
            'budgetSummary' => $budgetSummary,
            'budgetAlerts' => $budgetSummary['alerts'],
            'fixedVariable' => $fixedVariable,
            'yearOverYear' => $yearOverYear,
            'statusLabels' => BudgetService::STATUS_LABELS,
        ]);
    }

    /**
     * @return array<int, array{label: string, income: int, expense: int}>
     */
    private function buildTrend(Carbon $currentMonth): array
    {
        $start = $currentMonth->copy()->subMonths(11)->startOfMonth();
        $end = $currentMonth->copy()->endOfMonth();

        $transactions = Transaction::query()
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->get(['transaction_date', 'type', 'amount'])
            ->groupBy(fn (Transaction $t) => $t->transaction_date->format('Y-m'));

        $trend = [];

        for ($i = 11; $i >= 0; $i--) {
            $target = $currentMonth->copy()->subMonths($i);
            $key = $target->format('Y-m');
            $monthTx = $transactions->get($key, collect());

            $trend[] = [
                'label' => $target->format('Y/m'),
                'income' => (int) $monthTx->where('type', 'income')->sum('amount'),
                'expense' => (int) $monthTx->where('type', 'expense')->sum('amount'),
            ];
        }

        return $trend;
    }

}
