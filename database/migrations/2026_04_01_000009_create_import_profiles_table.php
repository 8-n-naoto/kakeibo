<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CSVの列の対応を、カード会社ごとに覚えておく。
     *
     * 同じカード会社のCSVは毎月同じ形で来る。自動判定が外れたときに
     * 毎月同じ手直しを繰り返すのは無駄なので、1回直したら次から自動で当てる。
     */
    public function up(): void
    {
        Schema::create('import_profiles', function (Blueprint $table) {
            $table->id();
            // ヘッダー行の中身から作る印。同じ並びのCSVなら同じ値になる
            $table->string('signature', 64)->unique();
            // 画面に出す名前（最初に取り込んだファイル名）
            $table->string('label')->nullable();
            $table->json('mapping');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_profiles');
    }
};
