<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\CsvImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * クレジットカード明細などのCSVを取り込む。
 * アップロード → 解析結果のプレビュー(セッション保持) → 確定登録 の3ステップ。
 */
class TransactionImportController extends Controller
{
    private const SESSION_KEY = 'csv_import_rows';

    public function create()
    {
        return view('imports.create');
    }

    public function store(Request $request, CsvImportService $service)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,tsv'],
        ]);

        try {
            $parsed = $service->parse($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return back()->withErrors(['file' => 'CSVの解析に失敗しました: '.$e->getMessage()]);
        }

        if ($parsed['rows'] === []) {
            return back()->withErrors(['file' => '取り込める行が見つかりませんでした。']);
        }

        $request->session()->put(self::SESSION_KEY, $parsed['rows']);

        return redirect()->route('imports.preview');
    }

    public function preview(Request $request)
    {
        $rows = $request->session()->get(self::SESSION_KEY);

        if (empty($rows)) {
            return redirect()->route('imports.create')
                ->withErrors(['file' => '取り込み対象のデータがありません。もう一度CSVをアップロードしてください。']);
        }

        return view('imports.preview', [
            'rows' => $rows,
            'categories' => Category::orderBy('type')->orderBy('sort_order')->get(),
            'importableCount' => collect($rows)->where('importable', true)->where('duplicate', false)->count(),
            'duplicateCount' => collect($rows)->where('duplicate', true)->count(),
            'errorCount' => collect($rows)->where('importable', false)->count(),
        ]);
    }

    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.import' => ['nullable', 'boolean'],
            'rows.*.transaction_date' => ['required_with:rows.*.import', 'nullable', 'date'],
            'rows.*.type' => ['required_with:rows.*.import', 'nullable', 'in:income,expense'],
            'rows.*.category_id' => ['nullable', 'exists:categories,id'],
            'rows.*.shop_name' => ['nullable', 'string', 'max:255'],
            'rows.*.amount' => ['required_with:rows.*.import', 'nullable', 'integer', 'min:0'],
        ]);

        $targets = collect($validated['rows'])->filter(fn (array $row) => ! empty($row['import']));

        if ($targets->isEmpty()) {
            return back()->withErrors(['rows' => '取り込む行が選択されていません。']);
        }

        $created = 0;

        DB::transaction(function () use ($targets, &$created) {
            foreach ($targets as $row) {
                Transaction::create([
                    'transaction_date' => $row['transaction_date'],
                    'type' => $row['type'],
                    'category_id' => $row['category_id'] ?? null,
                    'shop_name' => $row['shop_name'] ?? null,
                    'memo' => 'CSV取込',
                    'amount' => (int) $row['amount'],
                ]);
                $created++;
            }
        });

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('transactions.index')
            ->with('status', "CSVから{$created}件の取引を登録しました。");
    }
}
