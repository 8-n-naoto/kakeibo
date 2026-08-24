<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesMonth;
use App\Models\Budget;
use App\Models\Category;
use App\Services\BudgetSuggestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 過去実績から予算を提案し、選んだものだけを予算として登録する画面。
 *
 * 提案をそのまま自動で登録することはしない。予算は自分で決めたものでないと守れないので、
 * 「数字を出す」「直せるようにする」「確定は本人」の3段構えにしている。
 */
class BudgetSuggestionController extends Controller
{
    use ResolvesMonth;

    /** 画面のプルダウンに出す選択肢。ここに無い値は既定に戻す */
    public const LOOKBACK_OPTIONS = [3, 6, 12, 24];

    public function __construct(private readonly BudgetSuggestionService $service)
    {
    }

    public function index(Request $request)
    {
        $month = $this->resolveMonth($request->input('month'));
        $lookback = $this->resolveLookback($request->input('lookback'));

        return view('budgets.suggestions', [
            'month' => $month,
            'lookback' => $lookback,
            'suggestion' => $this->service->suggest($month, $lookback),
            'minMonths' => BudgetSuggestionService::MIN_MONTHS,
            'lookbackOptions' => self::LOOKBACK_OPTIONS,
        ]);
    }

    public function store(Request $request)
    {
        $month = $this->resolveMonth($request->input('month'));

        $validated = $request->validate([
            'scope' => ['required', 'in:default,month'],
            'selected' => ['required', 'array', 'min:1', 'max:200'],
            'selected.*' => ['required', 'string', 'max:32'],
            'amounts' => ['required', 'array', 'max:200'],
            // 1件でも桁を間違えたまま通すと予算アラートが意味を失うので上限も見る
            'amounts.*' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ]);

        $targetMonth = $validated['scope'] === 'month' ? $month->toDateString() : null;

        // 支出カテゴリ以外の id を送られても登録しない
        $expenseIds = Category::expense()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $applied = 0;
        $replaced = 0;
        $emptyAmount = 0;
        $invalidKey = 0;

        /** @var array<int, int|null> $written 書き込んだ category_id（全体は null） */
        $written = [];

        DB::transaction(function () use ($validated, $targetMonth, $expenseIds, &$applied, &$replaced, &$emptyAmount, &$invalidKey, &$written): void {
            foreach ($this->canonicalKeys($validated['selected']) as $key) {
                if ($key === 'overall') {
                    $categoryId = null;
                } elseif (ctype_digit($key) && in_array((int) $key, $expenseIds, true)) {
                    $categoryId = (int) $key;
                } else {
                    $invalidKey++;

                    continue;
                }

                $amount = $validated['amounts'][$key] ?? null;

                // 0円の予算は「消化率0%＝順調」と表示されてしまい、予算が無いより悪い
                if ($amount === null || (int) $amount <= 0) {
                    $emptyAmount++;

                    continue;
                }

                // memo は触らない。手で書いたメモを提案の適用で消したくない
                $budget = Budget::updateOrCreate(
                    ['category_id' => $categoryId, 'month' => $targetMonth],
                    ['amount' => (int) $amount],
                );

                // 提案の適用は「まとめて上書きする」操作なので確認は出さない
                // （画面に現在の予算が並んでいる）。ただし何件置き換えたかは伝える
                if (! $budget->wasRecentlyCreated) {
                    $replaced++;
                }

                $applied++;
                $written[] = $categoryId;
            }
        });

        if ($applied === 0) {
            // 入力を持ち帰らないと、手で直した金額が全部消えてしまう
            return back()
                ->withInput()
                ->withErrors(['selected' => '登録できる予算がありませんでした。金額（1円以上）とカテゴリを確認してください。']);
        }

        $scopeLabel = $targetMonth === null ? '毎月（デフォルト）' : $month->format('Y年n月');
        $message = sprintf('%s の予算を %d 件登録しました。', $scopeLabel, $applied);

        if ($replaced > 0) {
            $message .= sprintf('（うち %d 件は、すでにあった予算を置き換えました）', $replaced);
        }

        if ($emptyAmount > 0) {
            $message .= sprintf('（金額が空か0円のため %d 件は見送りました）', $emptyAmount);
        }

        if ($invalidKey > 0) {
            $message .= sprintf('（対象にできないカテゴリが %d 件ありました）', $invalidKey);
        }

        // デフォルト予算を入れても、その月だけの予算があるとそちらが優先される。
        // 「登録したのに画面の数字が変わらない」を黙って起こさない。
        if ($targetMonth !== null) {
            return redirect()
                ->route('budgets.index', ['month' => $month->format('Y-m')])
                ->with('status', $message);
        }

        $shadowed = Budget::query()
            ->where('month', $month->toDateString())
            ->where(function ($query) use ($written) {
                $ids = array_values(array_filter($written, fn ($id) => $id !== null));

                if ($ids !== []) {
                    $query->whereIn('category_id', $ids);
                }

                if (in_array(null, $written, true)) {
                    $query->orWhereNull('category_id');
                }
            })
            ->count();

        if ($shadowed > 0) {
            $message .= sprintf(
                '（%s には月指定の予算が %d 件あり、その月はそちらが優先されます）',
                $month->format('Y年n月'),
                $shadowed,
            );
        }

        return redirect()
            ->route('budgets.index', ['month' => $month->format('Y-m')])
            ->with('status', $message);
    }

    /**
     * "07" と "7" のような表記ゆれを潰してから重複を除く。
     * 潰さないと同じカテゴリに2回書き込み、件数の表示もずれる。
     *
     * @param  array<int, string>  $selected
     * @return array<int, string>
     */
    private function canonicalKeys(array $selected): array
    {
        $keys = array_map(
            fn (string $key): string => $key === 'overall' || ! ctype_digit($key) ? $key : (string) (int) $key,
            $selected,
        );

        return array_values(array_unique($keys));
    }

    /**
     * 画面のプルダウンと計算に使う値をずらさない。
     * 選択肢に無い値をそのまま計算に使うと、プルダウンには別の値が表示されたまま
     * 「再計算」を押した瞬間に結果が変わる、という分かりにくい状態になる。
     */
    private function resolveLookback(mixed $lookback): int
    {
        if (! is_scalar($lookback) || ! ctype_digit((string) $lookback)) {
            return BudgetSuggestionService::LOOKBACK_MONTHS;
        }

        $value = (int) $lookback;

        return in_array($value, self::LOOKBACK_OPTIONS, true)
            ? $value
            : BudgetSuggestionService::LOOKBACK_MONTHS;
    }
}
