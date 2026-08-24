<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'transactions_recurring_date_unique';

    /**
     * 同じ定期支出を同じ日に二重計上できないようにする。
     *
     * 「計上する」ボタンをダブルタップすると2本のリクエストがほぼ同時に走り、
     * どちらも「まだ計上されていない」と判断して2件作ってしまう。
     * アプリ側のチェックだけでは競合を防げないのでDBに一意制約を置く。
     *
     * 制約の粒度は「日」。同じ月でも計上日を変えれば2件入るが、
     * 月単位の重複は RecurringTransactionService::postedRuleIds() のアプリ側判定が担う。
     *
     * recurring_transaction_id が NULL の行（手入力・レシート・CSV）は
     * MySQL / SQLite とも一意制約の対象外なので影響しない。
     */
    public function up(): void
    {
        $this->unlinkDuplicates();

        Schema::table('transactions', function (Blueprint $table) {
            $table->unique(['recurring_transaction_id', 'transaction_date'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }

    /**
     * すでに二重計上されている取引があると一意制約を張れないので、
     * 先に「2件目以降の定期支出との紐付け」を外して手入力扱いに落とす。
     *
     * 金額を手で直している可能性があるので、行そのものは消さない。
     */
    private function unlinkDuplicates(): void
    {
        $duplicates = DB::table('transactions')
            ->selectRaw('recurring_transaction_id, transaction_date, MIN(id) as keep_id')
            ->whereNotNull('recurring_transaction_id')
            ->groupBy('recurring_transaction_id', 'transaction_date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('transactions')
                ->where('recurring_transaction_id', $duplicate->recurring_transaction_id)
                ->where('transaction_date', $duplicate->transaction_date)
                ->where('id', '!=', $duplicate->keep_id)
                ->update(['recurring_transaction_id' => null]);
        }
    }
};
