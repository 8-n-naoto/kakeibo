<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * レシート画像として受け付けられるファイルかを判定する。
 *
 * Laravel の `mimes` ルールは拡張子ではなく「中身から推定した MIME タイプ」で判定する。
 * HEIC を知らない libmagic の環境では iPhone の写真が application/octet-stream になり、
 * 正しいファイルなのに弾かれてしまう。
 * そこで「クライアントの拡張子」と「ファイル先頭のシグネチャ」の両方で見る。
 *
 * 実際に保存するときの拡張子は detectFormat() の結果を使う。
 * 中身が PNG なのに名前が .jpg というファイル（端末側のリネームでよくある）を
 * そのまま .jpg で保存すると、AI に image/jpeg と申告して PNG を送ることになり解析が落ちるため。
 */
class SupportedReceiptImage implements ValidationRule
{
    /** 受け付ける拡張子 */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif', 'avif'];

    /** ISO-BMFF(HEIF系)のブランド */
    private const HEIF_BRANDS = [
        'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs', 'mif1', 'msf1',
    ];

    /** ISO-BMFF(AVIF)のブランド */
    private const AVIF_BRANDS = ['avif', 'avis'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('画像ファイルをアップロードしてください。');

            return;
        }

        if (! in_array(strtolower($value->getClientOriginalExtension()), self::EXTENSIONS, true)) {
            $fail('JPG / PNG / WebP / GIF / HEIC / AVIF の画像を選んでください。');

            return;
        }

        if (self::detectFormat($value->getRealPath()) === null) {
            $fail('画像として読み取れないファイルです。撮り直してからもう一度お試しください。');
        }
    }

    /**
     * ファイル先頭のシグネチャから形式を判定し、保存に使う拡張子を返す。
     * 判定できなければ null。
     */
    public static function detectFormat(string|false $path): ?string
    {
        if ($path === false || ! is_readable($path)) {
            return null;
        }

        $head = (string) @file_get_contents($path, false, null, 0, 16);

        if (strlen($head) < 12) {
            return null;
        }

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'jpg';
        }

        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }

        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'gif';
        }

        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }

        if (substr($head, 4, 4) === 'ftyp') {
            $brand = strtolower(substr($head, 8, 4));

            if (in_array($brand, self::HEIF_BRANDS, true)) {
                return 'heic';
            }

            if (in_array($brand, self::AVIF_BRANDS, true)) {
                return 'avif';
            }
        }

        return null;
    }
}
