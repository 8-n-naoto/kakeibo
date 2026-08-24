<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 店名からカテゴリを決めるルール。
     *
     * 取引を登録・更新するたびに「この店名はこのカテゴリ」を覚えておき、
     * 次回のCSV取込やレシート解析で最優先に適用する。
     */
    public function up(): void
    {
        Schema::create('merchant_category_rules', function (Blueprint $table) {
            $table->id();
            // 正規化済みの店名(小文字・半角化)。部分一致の判定にも使う
            $table->string('pattern')->unique();
            // 画面表示用に、覚えたときの元の店名も残す
            $table->string('display_name')->nullable();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            // learned: 取引の登録から自動で覚えたもの / manual: 画面から手で登録したもの
            $table->string('source', 16)->default('learned');
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_category_rules');
    }
};
