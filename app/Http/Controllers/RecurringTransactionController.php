<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesMonth;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Services\RecurringTransactionService;
use App\Support\DomainLimits;
use App\Support\MonthParser;
use Illuminate\Http\Request;

class RecurringTransactionController extends Controller
{
    use ResolvesMonth;

    public function __construct(private readonly RecurringTransactionService $service)
    {
    }

    public function index(Request $request)
    {
        $month = $this->resolveMonth($request->input('month'));

        return view('recurring.index', [
            'month' => $month,
            'recurrings' => RecurringTransaction::with('category')
                ->orderByDesc('is_active')
                ->orderBy('day_of_month')
                ->orderBy('id')
                ->get(),
            'pending' => $this->service->pendingFor($month),
        ]);
    }

    public function create()
    {
        return view('recurring.create', ['categories' => $this->categories()]);
    }

    public function store(Request $request)
    {
        RecurringTransaction::create($this->validated($request));

        return redirect()->route('recurring.index')->with('status', '定期支出を登録しました。');
    }

    public function edit(RecurringTransaction $recurring)
    {
        return view('recurring.edit', [
            'recurring' => $recurring,
            'categories' => $this->categories(),
        ]);
    }

    public function update(Request $request, RecurringTransaction $recurring)
    {
        $recurring->update($this->validated($request));

        return redirect()->route('recurring.index')->with('status', '定期支出を更新しました。');
    }

    public function destroy(RecurringTransaction $recurring)
    {
        $recurring->delete();

        return redirect()->route('recurring.index')
            ->with('status', '定期支出を削除しました。計上済みの取引はそのまま残ります。');
    }

    /**
     * 指定月の未計上分をまとめて取引として登録する。
     */
    public function post(Request $request)
    {
        // 計上は書き込み処理なので、月の指定が怪しいときは黙って当月に読み替えない
        $month = MonthParser::parse($request->input('month'));

        if ($month === null) {
            return redirect()->route('recurring.index')
                ->withErrors(['month' => '対象月の指定が正しくありません。YYYY-MM の形式で指定してください。']);
        }

        $result = $this->service->post($month);

        $mismatchNote = ($result['mismatched'] ?? 0) > 0
            ? '（'.$result['mismatched'].'件は、定期支出の種別とカテゴリの種別が'
                .'合っていなかったので未分類で計上しました。定期支出の設定を見直してください）'
            : '';

        if ($result['created'] === 0) {
            return redirect()->route('recurring.index', ['month' => $month->format('Y-m')])
                ->with('status', $month->format('Y年n月').'に計上する定期支出はありませんでした。'.$mismatchNote);
        }

        $message = $month->format('Y年n月').'の定期支出を'.$result['created'].'件計上しました。';

        if ($result['skipped'] > 0) {
            $message .= '（'.$result['skipped'].'件は計上済みのためスキップしました）';
        }

        $message .= $mismatchNote;

        return redirect()->route('recurring.index', ['month' => $month->format('Y-m')])->with('status', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:income,expense'],
            'category_id' => ['nullable', DomainLimits::categoryRule($request->input('type'))],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:255'],
            'amount' => array_merge(['required'], DomainLimits::amountRules()),
            'day_of_month' => ['required', 'integer', 'min:1', 'max:31'],
            'starts_on' => array_merge(['nullable'], DomainLimits::dateRules()),
            // 終了日が開始日より前だと appliesTo() が常に false になり、
            // **黙って計上されなくなる**（家賃が抜けた家計簿になる）。画面には何も出ない。
            'ends_on' => array_merge(['nullable'], DomainLimits::dateRules(), ['after_or_equal:starts_on']),
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        return $validated;
    }

    private function categories()
    {
        return Category::orderBy('type')->orderBy('sort_order')->get();
    }

}
