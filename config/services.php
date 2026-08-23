<?php

return [

    'mail' => [
        'domain' => env('MAIL_DOMAIN'),
    ],

    // レシート画像の解析にどのAIを使うか（gemini / claude）
    'receipt_ai' => [
        'driver' => env('RECEIPT_AI_DRIVER', 'gemini'),
    ],

    // Gemini API — レシート画像の解析(OCR)に使用（既定）
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        'api_url' => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/interactions'),
        'api_revision' => env('GEMINI_API_REVISION', '2026-05-20'),
    ],

    // Claude(Anthropic) API — RECEIPT_AI_DRIVER=claude のときに使用
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
    ],

];
