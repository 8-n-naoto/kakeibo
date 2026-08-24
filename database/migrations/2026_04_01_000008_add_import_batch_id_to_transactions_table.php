<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * どのCSV取込で入った取引かを記録する。まとめて取り消すために使う。
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('import_batch_id')
                ->nullable()
                ->after('recurring_transaction_id')
                ->constrained('import_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // SQLite は外部キーの DROP に対応していないので、列だけ落とす
        $supportsDroppingForeignKeys = Schema::getConnection()->getDriverName() !== 'sqlite';

        Schema::table('transactions', function (Blueprint $table) use ($supportsDroppingForeignKeys) {
            if ($supportsDroppingForeignKeys) {
                $table->dropForeign(['import_batch_id']);
            }

            $table->dropColumn('import_batch_id');
        });
    }
};
