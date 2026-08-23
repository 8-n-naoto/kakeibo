<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * レシート/明細画像を Claude(Anthropic) API に送信し、
 * 日付・店名・金額・カテゴリ候補・品目などを構造化データとして抽出するサービス。
 *
 * `.env` の RECEIPT_AI_DRIVER=claude のときに使われる。
 */
class ClaudeReceiptParser extends AbstractReceiptParser
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {
    }

    public function parse(string $absoluteImagePath): array
    {
        $apiKey = $this->apiKey ?? config('services.anthropic.api_key');
        $model = $this->model ?? config('services.anthropic.model');

        if (empty($apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY が .env に設定されていません。');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ])->timeout(self::TIMEOUT_SECONDS)->post(self::API_URL, [
            'model' => $model,
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $this->detectMediaType($absoluteImagePath),
                                'data' => $this->encodeImage($absoluteImagePath),
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $this->buildPrompt(),
                        ],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Claude APIの呼び出しに失敗しました: '.$response->body());
        }

        $json = $response->json();
        $parsed = $this->decodeJson($json['content'][0]['text'] ?? '', 'Claude');

        return $this->normalize($parsed, $json);
    }
}
