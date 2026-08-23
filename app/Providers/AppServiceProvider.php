<?php

namespace App\Providers;

use App\Services\ClaudeReceiptParser;
use App\Services\GeminiReceiptParser;
use App\Services\ReceiptParser;
use InvalidArgumentException;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // レシート解析に使うAIを .env の RECEIPT_AI_DRIVER で切り替える
        $this->app->bind(ReceiptParser::class, function ($app) {
            $driver = config('services.receipt_ai.driver', 'gemini');

            return match ($driver) {
                'gemini' => $app->make(GeminiReceiptParser::class),
                'claude', 'anthropic' => $app->make(ClaudeReceiptParser::class),
                default => throw new InvalidArgumentException(
                    "RECEIPT_AI_DRIVER の値が不正です: {$driver}（gemini または claude）"
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
