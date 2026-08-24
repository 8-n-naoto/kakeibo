<?php

namespace App\Services;

use App\Support\ReceiptValueNormalizer;
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

    /** 品目の合計と総額のずれを「合わない」と見なす割合 */
    private const ITEM_TOTAL_TOLERANCE = 0.15;

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
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'avif' => 'image/avif',
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
        // AI が JSON として妥当だが中身の無いもの（`[]`、`{}`、`{"error":"not a receipt"}`）を
        // 返すことがある。これを成功として保存すると status=processed になり、
        // 以後どうやってもAIに読み直させられない「詰んだレシート」ができる。
        $amount = ReceiptValueNormalizer::amount($parsed['total_amount'] ?? null);
        $shop = ReceiptValueNormalizer::text($parsed['shop_name'] ?? null);
        $date = ReceiptValueNormalizer::date($parsed['transaction_date'] ?? null);
        $items = ReceiptValueNormalizer::items($parsed['items'] ?? null);

        // 「何ひとつ読めていない」ときだけ失敗にする。
        // 店名が切れていても品目と日付が読めていれば、金額を手で足すほうが速い。
        if ($amount === null && $shop === null && $date === null && $items === []) {
            throw new RuntimeException(
                'レシートとして読み取れませんでした（金額も店名も日付も品目も取得できませんでした）。'
                .'ピントや明るさを確認して撮り直すか、手入力で登録してください。'
            );
        }

        return [
            // AIは想定外の型（配列やオブジェクト）を返すことがある。
            // ここで文字列に揃えておかないと、あとで Blade の出力で TypeError になり
            // 「1件の壊れたレコードのせいで未登録レシート画面ごと開けない」事故になる。
            'transaction_date' => $date,
            'shop_name' => $shop,
            'total_amount' => $amount,
            'type' => in_array($parsed['type'] ?? null, ['income', 'expense'], true) ? $parsed['type'] : 'expense',
            'suggested_category' => ReceiptValueNormalizer::text($parsed['suggested_category'] ?? null),
            // AI が items を配列以外(文字列など)で返すことがあるので形を揃える
            'items' => $items,
            'memo' => ReceiptValueNormalizer::text($parsed['memo'] ?? null),
            // 目視で確かめたほうがよい理由（無ければ空配列）
            'warnings' => self::warningsFor($amount, $date, $items),
            'raw_response' => $rawResponse,
        ];
    }

    /**
     * 「読めてはいるが、目視で確かめたほうがよい」理由を返す。
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, string>
     */
    public static function warningsFor(?int $amount, ?string $date, array $items): array
    {
        $warnings = [];

        if ($amount === null || $amount === 0) {
            $warnings[] = '合計金額を読み取れませんでした';
        }

        if ($date === null) {
            $warnings[] = '日付を読み取れませんでした';
        }

        $itemTotal = 0;

        foreach ($items as $item) {
            $itemTotal += is_numeric($item['amount'] ?? null) ? (int) $item['amount'] : 0;
        }

        if ($items !== [] && $itemTotal === 0) {
            // 品目名は読めたが金額が全部0。価格の列が切れている写真でよく起きる
            $warnings[] = '品目の金額を読み取れませんでした';
        }

        // 品目の合計と総額がずれていたら、品目を読み落としているか値引きを拾えていない。
        // しきい値が1割ちょうどだと、税抜き品目＋税込み総額（差はぴったり9.09%）が
        // 端数調整やレジ袋代1つで越えてしまうので少し広げてある。
        if ($amount !== null && $amount > 0 && $itemTotal > 0
            && abs($itemTotal - $amount) > min($itemTotal, $amount) * self::ITEM_TOTAL_TOLERANCE) {
            $warnings[] = sprintf('品目の合計（¥%s）が総額（¥%s）と合いません', number_format($itemTotal), number_format($amount));
        }

        return $warnings;
    }
}
