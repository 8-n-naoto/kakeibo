<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * レシート/明細画像を Claude(Anthropic) API に送信し、
 * 日付・店名・金額・カテゴリ候補・品目などを構造化データとして抽出するサービス。
 */
class ClaudeReceiptParser
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
    ) {
    }

    /**
     * @return array{
     *   transaction_date: ?string,
     *   shop_name: ?string,
     *   total_amount: ?int,
     *   suggested_category: ?string,
     *   type: string,
     *   items: array<int, array{name: ?string, amount: ?int}>,
     *   memo: ?string,
     * }
     */
    public function parse(string $absoluteImagePath): array
    {
        $apiKey = $this->apiKey ?? config('services.anthropic.api_key');
        $model = $this->model ?? config('services.anthropic.model');

        if (empty($apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY が .env に設定されていません。');
        }

        $imageData = base64_encode(file_get_contents($absoluteImagePath));
        $mediaType = $this->detectMediaType($absoluteImagePath);

        $categories = implode('、', [
            '食費', '外食', '日用品', '住居費', '水道光熱費', '通信費',
            '交通費', '医療・健康', '衣服・美容', '娯楽・趣味', '教育',
            'その他支出', '給与', '副業', 'その他収入',
        ]);

        $prompt = <<<PROMPT
        添付されたレシートまたは明細書の画像を読み取り、家計簿に登録するための情報を
        次のJSONスキーマ「のみ」で出力してください。説明文やコードブロックの記号は不要です。

        {
          "transaction_date": "YYYY-MM-DD形式の日付。読み取れない場合はnull",
          "shop_name": "店舗名・支払先名。読み取れない場合はnull",
          "total_amount": "合計金額(円、整数、税込)。読み取れない場合はnull",
          "type": "income または expense のいずれか(レシートは通常expense)",
          "suggested_category": "次の候補から最も近いもの1つ: {$categories}",
          "items": [
            {"name": "品目名", "amount": "金額(整数)"}
          ],
          "memo": "補足があれば短く。なければnull"
        }
        PROMPT;

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ])->timeout(60)->post(self::API_URL, [
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
                                'media_type' => $mediaType,
                                'data' => $imageData,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Claude APIの呼び出しに失敗しました: '.$response->body());
        }

        $json = $response->json();
        $text = $json['content'][0]['text'] ?? '';
        $text = trim($text);
        // ```json ... ``` で囲まれて返ってきた場合の除去
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);

        $parsed = json_decode($text, true);

        if (! is_array($parsed)) {
            throw new RuntimeException('Claude APIの応答をJSONとして解析できませんでした: '.$text);
        }

        return [
            'transaction_date' => $parsed['transaction_date'] ?? null,
            'shop_name' => $parsed['shop_name'] ?? null,
            'total_amount' => isset($parsed['total_amount']) ? (int) $parsed['total_amount'] : null,
            'type' => in_array($parsed['type'] ?? null, ['income', 'expense'], true) ? $parsed['type'] : 'expense',
            'suggested_category' => $parsed['suggested_category'] ?? null,
            'items' => $parsed['items'] ?? [],
            'memo' => $parsed['memo'] ?? null,
            'raw_response' => $json,
        ];
    }

    private function detectMediaType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
