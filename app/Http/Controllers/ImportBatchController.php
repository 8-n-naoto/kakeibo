<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;

/**
 * CSV取込の履歴と、取込1回ぶんの取り消し。
 *
 * 「取り消せる」と分かっていることが、取り込むこと自体の心理的な負担を下げる。
 */
class ImportBatchController extends Controller
{
    /** 履歴として残す件数 */
    private const LIMIT = 50;

    public function index()
    {
        $batches = ImportBatch::query()
            ->withCount([
                'transactions',
                'revertableTransactions as revertable_count',
                'editedTransactions as edited_count',
            ])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        return view('imports.batches', ['batches' => $batches]);
    }

    public function destroy(ImportBatch $importBatch)
    {
        $reverted = 0;
        $kept = 0;

        DB::transaction(function () use ($importBatch, &$reverted, &$kept) {
            // 取り込んだあとに手で直した行は残す。直したということは人の判断が入っている
            $kept = $importBatch->editedTransactions()->count();

            $reverted = DB::table('transactions')
                ->where('import_batch_id', $importBatch->id)
                ->whereColumn('updated_at', '<=', 'created_at')
                ->delete();
        });

        if ($reverted === 0 && $kept === 0) {
            return redirect()->route('imports.batches')
                ->with('status', 'この取込で入った取引はもう残っていません。');
        }

        $message = sprintf('%s の取込から入った%d件を取り消しました。', $importBatch->created_at?->format('n月j日 H:i') ?? '', $reverted);

        if ($kept > 0) {
            $message .= sprintf('（取込後に手で直した%d件は残しました）', $kept);
        }

        return redirect()->route('imports.batches')->with('status', $message);
    }
}
