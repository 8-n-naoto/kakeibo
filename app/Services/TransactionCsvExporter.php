<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 取引データをCSV(Excelで開けるUTF-8 BOM付き)として出力する。
 */
class TransactionCsvExporter
{
    public const HEADER = ['日付', '種別', 'カテゴリ', '固定/変動', '店名', 'メモ', '金額'];

    /**
     * @param  Builder<Transaction>  $query
     */
    public function stream(Builder $query, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            // Excelで文字化けしないようにBOMを付ける
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::HEADER, ',', '"', '\\');

            $query->with('category')->orderBy('transaction_date')->orderBy('id')
                ->chunk(500, function ($transactions) use ($handle) {
                    foreach ($transactions as $transaction) {
                        fputcsv($handle, $this->toRow($transaction), ',', '"', '\\');
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<int, string|int>
     */
    public function toRow(Transaction $transaction): array
    {
        return [
            $transaction->transaction_date->format('Y-m-d'),
            $transaction->type === 'income' ? '収入' : '支出',
            $transaction->category?->name ?? '未分類',
            $transaction->category?->expense_nature_label ?? '',
            $transaction->shop_name ?? '',
            $transaction->memo ?? '',
            (int) $transaction->amount,
        ];
    }
}
