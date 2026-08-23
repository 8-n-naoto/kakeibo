<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_images', function (Blueprint $table) {
            $table->id();
            $table->string('path'); // storage/app/public 配下のパス
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->json('raw_response')->nullable(); // Claude APIの生レスポンス
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_images');
    }
};
