<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI の解析結果を receipt_images に保存する。
     *
     * これまで解析結果はセッションのフラッシュデータにしか無かったため、
     * 確認画面をリロード・ブラウザバックすると内容が消え、
     * 画像を再アップロードして API を再度呼ぶ必要があった。
     */
    public function up(): void
    {
        Schema::table('receipt_images', function (Blueprint $table) {
            $table->json('parsed_data')->nullable()->after('raw_response');
        });
    }

    public function down(): void
    {
        Schema::table('receipt_images', function (Blueprint $table) {
            $table->dropColumn('parsed_data');
        });
    }
};
