<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * どの定期支出から計上された取引かを記録する。
     * 同じ月に二重計上しないための目印にも使う。
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('recurring_transaction_id')
                ->nullable()
                ->after('receipt_image_id')
                ->constrained('recurring_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // SQLite は外部キーの DROP に対応していないので、列だけ落とす
        $supportsDroppingForeignKeys = Schema::getConnection()->getDriverName() !== 'sqlite';

        Schema::table('transactions', function (Blueprint $table) use ($supportsDroppingForeignKeys) {
            if ($supportsDroppingForeignKeys) {
                $table->dropForeign(['recurring_transaction_id']);
            }

            $table->dropColumn('recurring_transaction_id');
        });
    }
};
