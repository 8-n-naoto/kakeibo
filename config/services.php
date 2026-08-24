<?php

return [

    'mail' => [
        'domain' => env('MAIL_DOMAIN'),
    ],

    // レシート画像のアップロードまわりの設定
    'receipt' => [
        // 1回のアップロードで受け付ける最大枚数。
        // アップロードは保存するだけでAIを呼ばないので、タイムアウトの心配は無い。
        // 上限は POST サイズ（post_max_size / upload_max_filesize）とのバランスで決める。
        'max_files_per_upload' => (int) env('RECEIPT_MAX_FILES_PER_UPLOAD', 20),
    ],

    // レシート画像の解析にどのAIを使うか（gemini / claude）
    'receipt_ai' => [
        'driver' => env('RECEIPT_AI_DRIVER', 'gemini'),
        // 1日にAIへ投げられる最大枚数。画面が繰り返し読み取ってしまっても
        // ここで頭を打つので、AIの請求が青天井にならない。有効範囲 1〜1000。
        'daily_limit' => (int) env('RECEIPT_AI_DAILY_LIMIT', 200),
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
