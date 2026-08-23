<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->unique(); // 月初日で保存(月1スナップショット)
            $table->unsignedBigInteger('cash_amount')->default(0); // 現金・預金
            $table->unsignedBigInteger('nisa_amount')->default(0); // NISA評価額
            $table->unsignedBigInteger('ideco_amount')->default(0); // iDeCo評価額
            $table->unsignedBigInteger('other_investment_amount')->default(0); // その他投資
            $table->string('memo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_snapshots');
    }
};
