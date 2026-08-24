<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\TransactionCsvExporter;
use App\Services\TransactionFilter;
use Illuminate\Http\Request;

class TransactionExportController extends Controller
{
    public function __invoke(
        Request $request,
        TransactionCsvExporter $exporter,
        TransactionFilter $filter,
    ) {
        // 一覧で絞り込んだ条件をそのまま引き継いで出力する
        $filters = $filter->fromRequest($request);

        $query = $filter->apply(Transaction::query(), $filters);

        $filename = 'kakeibo_'.($filters['month'] ?? 'all').'.csv';

        return $exporter->stream($query, $filename);
    }
}
