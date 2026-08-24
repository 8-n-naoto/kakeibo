<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->date('transaction_date');
            $table->enum('type', ['income', 'expense'])->default('expense');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('shop_name')->nullable(); // 店名(レシートから取得)
            $table->string('memo')->nullable();
            $table->unsignedBigInteger('amount'); // 円単位
            $table->foreignId('receipt_image_id')->nullable()->constrained('receipt_images')->nullOnDelete();
            $table->timestamps();

            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
