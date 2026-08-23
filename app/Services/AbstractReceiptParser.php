<?php

namespace App\Services;

use RuntimeException;

/**
 * レシート解析サービスの共通処理。
 *
 * プロンプトの組み立て・画像のbase64化・応答JSONの取り出し・戻り値の正規化は
 * どのAIを使っても同じなのでここにまとめ、API呼び出しだけを各実装に任せる。
 */
abstract class AbstractReceiptParser implements ReceiptParser
{
    /** 解析結果として提案させるカテゴリ候補 */
    protected const CATEGORY_CANDIDATES = [
        '食費', '外食', '日用品', '住居費', '水道光熱費', '通信費',
        '交通費', '医療・健康', '衣服・美容', '娯楽・趣味', '教育',
        'その他支出', '給与', '副業', 'その他収入',
    ];

    /** APIの応答待ち上限(秒) */
    protected const TIMEOUT_SECONDS = 60;

    abstract public function parse(string $absoluteImagePath): array;

    /**
     * 画像から抽出したい項目を指示するプロンプト。
     */
    protected function buildPrompt(): string
    {
        $categories = implode('、', static::CATEGORY_CANDIDATES);

        return <<<PROMPT
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
    }

    /**
     * 画像ファイルをbase64文字列にする。
     */
    protected function encodeImage(string $absoluteImagePath): string
    {
        $binary = @file_get_contents($absoluteImagePath);

        if ($binary === false) {
            throw new RuntimeException('レシート画像を読み込めませんでした: '.$absoluteImagePath);
        }

        return base64_encode($binary);
    }

    protected function detectMediaType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * AIが返したテキストからJSONを取り出して連想配列にする。
     *
     * @param  string  $driverLabel  例外メッセージに出すAI名（例: Gemini）
     * @return array<string, mixed>
     */
    protected function decodeJson(string $text, string $driverLabel): array
    {
        $text = trim($text);
        // ```json ... ``` で囲まれて返ってきた場合の除去
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);

        $parsed = json_decode((string) $text, true);

        if (! is_array($parsed)) {
            throw new RuntimeException($driverLabel.' APIの応答をJSONとして解析できませんでした: '.$text);
        }

        return $parsed;
    }

    /**
     * AIの出力を家計簿側で扱いやすい形に整える。
     *
     * @param  array<string, mixed>  $parsed
     * @param  mixed  $rawResponse
     * @return array<string, mixed>
     */
    protected function normalize(array $parsed, $rawResponse): array
    {
        return [
            'transaction_date' => $parsed['transaction_date'] ?? null,
            'shop_name' => $parsed['shop_name'] ?? null,
            'total_amount' => isset($parsed['total_amount']) ? (int) $parsed['total_amount'] : null,
            'type' => in_array($parsed['type'] ?? null, ['income', 'expense'], true) ? $parsed['type'] : 'expense',
            'suggested_category' => $parsed['suggested_category'] ?? null,
            'items' => $parsed['items'] ?? [],
            'memo' => $parsed['memo'] ?? null,
            'raw_response' => $rawResponse,
        ];
    }
}
