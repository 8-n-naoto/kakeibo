<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * 取引一覧とCSV出力で共通に使う絞り込み条件。
 *
 * 一覧で絞り込んだ条件をそのままCSVに引き継げるよう、
 * バリデーション・クエリの組み立て・合計の集計をここにまとめる。
 */
class TransactionFilter
{
    /** 並び順の選択肢 */
    public const SORTS = [
        'date_desc' => '日付が新しい順',
        'date_asc' => '日付が古い順',
        'amount_desc' => '金額が大きい順',
        'amount_asc' => '金額が小さい順',
    ];

    /** クエリ文字列として引き回すキー */
    public const KEYS = [
        'month', 'category_id', 'type', 'keyword',
        'date_from', 'date_to', 'amount_min', 'amount_max', 'sort',
    ];

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'type' => ['nullable', 'in:income,expense'],
            'keyword' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'amount_min' => ['nullable', 'integer', 'min:0'],
            'amount_max' => ['nullable', 'integer', 'min:0'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fromRequest(Request $request): array
    {
        $rules = self::rules();

        // 比較対象が空のときに gte / after_or_equal を付けると判定が不安定になるため、
        // 相手が入力されているときだけ大小関係を検証する
        if ($request->filled('date_from')) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }

        if ($request->filled('amount_min')) {
            $rules['amount_max'][] = 'gte:amount_min';
        }

        // 項目名は lang/ja/validation.php の attributes に集約している
        return $request->validate($rules);
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        // MySQL / SQLite どちらでも動くように whereYear / whereMonth を使う
        if (! empty($filters['month']) && preg_match('/^(\d{4})-(\d{2})$/', $filters['month'], $matches)) {
            $query->whereYear('transaction_date', (int) $matches[1])
                ->whereMonth('transaction_date', (int) $matches[2]);
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('transaction_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('transaction_date', '<=', $filters['date_to']);
        }

        if (isset($filters['amount_min']) && $filters['amount_min'] !== null && $filters['amount_min'] !== '') {
            $query->where('amount', '>=', (int) $filters['amount_min']);
        }

        if (isset($filters['amount_max']) && $filters['amount_max'] !== null && $filters['amount_max'] !== '') {
            $query->where('amount', '<=', (int) $filters['amount_max']);
        }

        if (! empty($filters['keyword'])) {
            // 「%」「_」は検索語として扱う。
            // エスケープ文字にバックスラッシュを使うと MySQL と SQLite で解釈が変わるので、
            // ESCAPE 句を明示したうえで「!」を使う。
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['keyword']);
            $keyword = '%'.$escaped.'%';

            $query->where(function (Builder $q) use ($keyword) {
                $q->whereRaw("shop_name LIKE ? ESCAPE '!'", [$keyword])
                    ->orWhereRaw("memo LIKE ? ESCAPE '!'", [$keyword]);
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function applySort(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'date_asc' => $query->orderBy('transaction_date')->orderBy('id'),
            'amount_desc' => $query->orderByDesc('amount')->orderByDesc('id'),
            'amount_asc' => $query->orderBy('amount')->orderBy('id'),
            default => $query->orderByDesc('transaction_date')->orderByDesc('id'),
        };
    }

    /**
     * 絞り込み結果の件数と収支。
     *
     * @param  Builder<Transaction>  $query
     * @return array{count: int, income: int, expense: int, balance: int}
     */
    public function summarize(Builder $query): array
    {
        // 件数は type でまとめた行の足し算にしない。想定外の type が1件でもあると
        // 静かに数え落とし、一括変更の件数チェック（画面の表示と突き合わせる）が
        // 永久に通らなくなる。
        $count = (clone $query)->reorder()->count();

        $rows = $query->reorder()
            ->selectRaw('type, COUNT(*) as row_count, SUM(amount) as total_amount')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $income = (int) ($rows['income']->total_amount ?? 0);
        $expense = (int) ($rows['expense']->total_amount ?? 0);

        return [
            'count' => $count,
            'income' => $income,
            'expense' => $expense,
            'balance' => $income - $expense,
        ];
    }
}
