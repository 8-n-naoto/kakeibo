<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * エンゲル係数の分子に数えるカテゴリを設定で持てるようにする。
     *
     * これまでは DashboardController がカテゴリ名 "食費" を文字列で直接見ていたため、
     * カテゴリを改名・削除するとエラーも出ないまま係数が 0 になっていた。
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('counts_as_food')->default(false)->after('expense_nature');
        });

        // 既存環境の表示が変わらないよう、これまで対象だった "食費" だけを引き継ぐ
        DB::table('categories')
            ->where('type', 'expense')
            ->where('name', '食費')
            ->update(['counts_as_food' => true]);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('counts_as_food');
        });
    }
};
