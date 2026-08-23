<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            // category_id が null の行は「支出全体の予算」
            $table->foreignId('category_id')->nullable()->constrained('categories')->cascadeOnDelete();
            // month が null の行は「毎月適用されるデフォルト予算」、日付が入っていればその月だけの予算
            $table->date('month')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index(['category_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
