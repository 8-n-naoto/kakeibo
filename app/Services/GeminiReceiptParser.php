<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * レシート/明細画像を Gemini API に送信し、
 * 日付・店名・金額・カテゴリ候補・品目などを構造化データとして抽出するサービス。
 *
 * 既定では Interactions API（/v1beta/interactions）を使う。
 * エンドポイントやAPIリビジョンは .env（GEMINI_API_URL / GEMINI_API_REVISION）で差し替えられる。
 *
 * `.env` の RECEIPT_AI_DRIVER=gemini のときに使われる（既定）。
 */
class GeminiReceiptParser extends AbstractReceiptParser
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {
    }

    public function parse(string $absoluteImagePath): array
    {
        $apiKey = $this->apiKey ?? config('services.gemini.api_key');
        $model = $this->model ?? config('services.gemini.model');
        $apiUrl = config('services.gemini.api_url');
        $apiRevision = config('services.gemini.api_revision');

        if (empty($apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY が .env に設定されていません。');
        }

        $headers = [
            'x-goog-api-key' => $apiKey,
            'content-type' => 'application/json',
        ];

        if (! empty($apiRevision)) {
            $headers['Api-Revision'] = $apiRevision;
        }

        $response = Http::withHeaders($headers)
            ->timeout(self::TIMEOUT_SECONDS)
            ->post($apiUrl, [
                'model' => $model,
                'input' => [
                    [
                        'type' => 'text',
                        'text' => $this->buildPrompt(),
                    ],
                    [
                        'type' => 'image',
                        'mime_type' => $this->detectMediaType($absoluteImagePath),
                        'data' => $this->encodeImage($absoluteImagePath),
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Gemini APIの呼び出しに失敗しました: '.$response->body());
        }

        $json = $response->json();
        $parsed = $this->decodeJson($this->extractText(is_array($json) ? $json : []), 'Gemini');

        return $this->normalize($parsed, $json);
    }

    /**
     * 応答本文から生成テキストを取り出す。
     *
     * Interactions API は steps[].content[].text、
     * 旧 generateContent 形式は candidates[].content.parts[].text に入るため両方見る。
     *
     * @param  array<string, mixed>  $json
     */
    private function extractText(array $json): string
    {
        if (is_string($json['output_text'] ?? null) && $json['output_text'] !== '') {
            return $json['output_text'];
        }

        $texts = [];

        foreach ($json['steps'] ?? [] as $step) {
            foreach ($step['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'text' && isset($content['text'])) {
                    $texts[] = (string) $content['text'];
                }
            }
        }

        if ($texts === []) {
            foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['text'])) {
                    $texts[] = (string) $part['text'];
                }
            }
        }

        return implode("\n", $texts);
    }
}
