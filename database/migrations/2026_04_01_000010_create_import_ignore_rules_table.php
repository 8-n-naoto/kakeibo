<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CSVから「取り込まない」店名のルール。
     *
     * カード明細には家計簿に入れたくない行が毎月同じように混じる。
     *   - 口座振替のカード引き落とし行そのもの（入れると二重計上になる）
     *   - すでに定期支出として自動計上している家賃・通信費
     * 毎月手でチェックを外すのをやめるためのもの。
     *
     * 自動分類（merchant_category_rules）とはテーブルを分けた。
     * あちらは「どのカテゴリにするか」、こちらは「そもそも入れるか」で、
     * 混ぜると片方を消したときにもう片方が巻き添えになる。
     */
    public function up(): void
    {
        Schema::create('import_ignore_rules', function (Blueprint $table) {
            $table->id();
            // 正規化済みの店名（小文字・半角化）。部分一致の判定に使う
            $table->string('pattern')->unique();
            // 画面表示用に、覚えたときの元の店名も残す
            $table->string('display_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_ignore_rules');
    }
};
