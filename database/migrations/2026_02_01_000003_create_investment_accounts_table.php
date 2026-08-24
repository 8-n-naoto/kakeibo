<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_accounts', function (Blueprint $table) {
            $table->id();
            $table->enum('account_type', ['nisa_growth', 'nisa_tsumitate', 'ideco']);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedBigInteger('annual_limit')->default(0); // 年間投資枠上限
            $table->unsignedBigInteger('contributed_amount')->default(0); // 年間拠出/投資済み額
            $table->unsignedBigInteger('evaluation_amount')->default(0); // 評価額
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->unique(['account_type', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_accounts');
    }
};
