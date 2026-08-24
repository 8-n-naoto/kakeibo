<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CSV取込を「1回ぶん」の単位で記録する。
     *
     * 取込を間違えたときに復旧する手段が「200件を手で消す」しか無いと、
     * 間違いに気づいても実質やり直せない。月1でまとめて取り込む運用だと
     * 気づくのが何ヶ月も後になるので、まとめて取り消せることが要る。
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('file_name')->nullable();
            // いまは csv だけ。将来レシートの一括登録なども同じ枠で扱えるように残す
            $table->string('source', 16)->default('csv');
            $table->unsignedInteger('row_count')->default(0);
            // 差引（支出をプラス、収入をマイナスとした合計）
            $table->bigInteger('total_amount')->default(0);
            $table->date('first_date')->nullable();
            $table->date('last_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
