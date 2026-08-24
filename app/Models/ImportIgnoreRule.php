<?php

namespace App\Models;

use App\Services\MerchantCategoryGuesser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * CSVから「取り込まない」店名のルール。
 *
 * 落とすのではなく、プレビューで既定のチェックを外すだけにしてある。
 * 黙って消すと、ルールを作ったことを忘れたころに
 * 「なぜか支出が少ない」という気づけない事故になる。
 */
class ImportIgnoreRule extends Model
{
    protected $fillable = [
        'pattern',
        'display_name',
    ];

    /**
     * 店名がどれかのルールに当たるか。当たったルールの表示名を返す。
     *
     * @param  Collection<int, self>  $rules
     */
    public static function matchIn(Collection $rules, ?string $shopName): ?string
    {
        $normalized = MerchantCategoryGuesser::normalize($shopName);

        if ($normalized === '') {
            return null;
        }

        // 長いパターンを優先（「イオン」と「イオンシネマ」なら後者）
        $match = $rules
            ->filter(fn (self $rule): bool => $rule->pattern !== '' && str_contains($normalized, $rule->pattern))
            ->sortByDesc(fn (self $rule): int => mb_strlen($rule->pattern))
            ->first();

        return $match?->display_name ?? $match?->pattern;
    }

    /**
     * 「この店は今後取り込まない」を覚える。すでにあれば何もしない。
     */
    public static function remember(?string $shopName): ?self
    {
        $normalized = MerchantCategoryGuesser::normalize($shopName);

        if ($normalized === '') {
            return null;
        }

        return static::firstOrCreate(
            ['pattern' => $normalized],
            ['display_name' => trim((string) $shopName)],
        );
    }
}
