<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 家賃・保険・サブスクのような毎月決まった収支。
     *
     * レシートもカード明細も出ないことが多く手入力が漏れやすいので、
     * 登録しておいて月次でまとめて計上できるようにする。
     */
    public function up(): void
    {
        Schema::create('recurring_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['income', 'expense'])->default('expense');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('shop_name')->nullable();
            $table->string('memo')->nullable();
            $table->unsignedBigInteger('amount');
            // 毎月何日に計上するか。月末日を超える指定はその月の末日に丸める
            $table->unsignedTinyInteger('day_of_month')->default(1);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_transactions');
    }
};
