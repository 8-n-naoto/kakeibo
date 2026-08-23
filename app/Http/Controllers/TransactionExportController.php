<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\TransactionCsvExporter;
use Illuminate\Http\Request;

class TransactionExportController extends Controller
{
    public function __invoke(Request $request, TransactionCsvExporter $exporter)
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);

        $query = Transaction::query();

        if ($month = $request->input('month')) {
            $query->whereYear('transaction_date', (int) substr($month, 0, 4))
                ->whereMonth('transaction_date', (int) substr($month, 5, 2));
        }

        if ($categoryId = $request->input('category_id')) {
            $query->where('category_id', $categoryId);
        }

        $filename = 'kakeibo_'.($month ?: 'all').'.csv';

        return $exporter->stream($query, $filename);
    }
}
