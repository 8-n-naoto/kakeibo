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

    /**
     * 出力トークンの上限。
     * 1024 だと品目の多いレシート（スーパーで30品目など）で JSON が途中で切れ、
     * 「品目ごとに分けたい」レシートほど必ず失敗していた。
     */
    private const MAX_TOKENS = 4096;

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
            'max_tokens' => self::MAX_TOKENS,
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

        // 途中で切れた応答は JSON として壊れる。原因の分からないエラーにしない
        if (($json['stop_reason'] ?? null) === 'max_tokens') {
            throw new RuntimeException(
                '品目が多すぎて読み取りが途中で切れました。レシートを分けて撮るか、'
                .'品目ごとの登録をあきらめて合計だけ手で入力してください。'
            );
        }

        $parsed = $this->decodeJson($json['content'][0]['text'] ?? '', 'Claude');

        return $this->normalize($parsed, $json);
    }
}
