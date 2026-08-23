<?php

return [

    'mail' => [
        'domain' => env('MAIL_DOMAIN'),
    ],

    // Claude(Anthropic) API — レシート画像の解析(OCR)に使用
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
    ],

];
