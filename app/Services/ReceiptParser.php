<?php

namespace App\Services;

/**
 * レシート/明細画像を解析して家計簿用の構造化データを返すサービスの共通インターフェース。
 *
 * 実装は Gemini / Claude の2種類があり、どちらを使うかは
 * `.env` の RECEIPT_AI_DRIVER（config: services.receipt_ai.driver）で切り替える。
 */
interface ReceiptParser
{
    /**
     * @return array{
     *   transaction_date: ?string,
     *   shop_name: ?string,
     *   total_amount: ?int,
     *   suggested_category: ?string,
     *   type: string,
     *   items: array<int, array{name: ?string, amount: ?int}>,
     *   memo: ?string,
     *   raw_response: mixed,
     * }
     */
    public function parse(string $absoluteImagePath): array;
}
