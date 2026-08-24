<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CSVの列の対応を、カード会社ごとに覚えたもの。
 *
 * 自動判定が外れたときに手で直した内容を、次回から自動で当てるためだけに存在する。
 */
class ImportProfile extends Model
{
    protected $fillable = [
        'signature',
        'label',
        'mapping',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
        ];
    }

    /**
     * 覚えた対応を、この印のCSVに対して保存する。
     *
     * @param  array<string, ?int>  $mapping
     */
    public static function remember(string $signature, array $mapping, ?string $label = null): self
    {
        // 同時に2回確定されても落ちないように updateOrCreate を使う。
        // 覚えるのに失敗して、成功した取り込みが500になっては本末転倒
        return static::updateOrCreate(
            ['signature' => $signature],
            array_filter([
                'label' => $label,
                'mapping' => self::sanitize($mapping),
            ], static fn ($value) => $value !== null),
        );
    }

    /**
     * 保存された対応。無ければ null。
     *
     * @return array<string, ?int>|null
     */
    public static function mappingFor(string $signature): ?array
    {
        $profile = static::query()->where('signature', $signature)->first();

        return $profile === null ? null : self::sanitize((array) $profile->mapping);
    }

    /**
     * 画面から来た値を「列番号か null」だけに揃える。
     *
     * @param  array<string, mixed>  $mapping
     * @return array<string, ?int>
     */
    public static function sanitize(array $mapping): array
    {
        $clean = [];

        foreach (['header_row', 'date', 'shop', 'amount', 'income'] as $key) {
            $value = $mapping[$key] ?? null;

            $clean[$key] = (is_int($value) || (is_string($value) && ctype_digit($value)))
                ? (int) $value
                : null;
        }

        return $clean;
    }
}
