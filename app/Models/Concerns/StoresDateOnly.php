<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;

/**
 * 日付だけを持つカラム用のアクセサ/ミューテタ。
 *
 * Eloquent の date キャストは書き込み時に "Y-m-d H:i:s" へ整形する。
 * MySQL の DATE 型では時刻が切り捨てられるが、SQLite ではそのまま保存されるため、
 * 「MySQLでは動くのにテスト(SQLite)では一致しない」という差異が生まれる。
 * このトレイトは書き込みを常に "Y-m-d" に固定し、どちらのDBでも同じ値が入るようにする。
 */
trait StoresDateOnly
{
    protected static function dateOnlyAttribute(): Attribute
    {
        return Attribute::make(
            get: static fn ($value) => self::toDateOnly($value),
            set: static fn ($value) => self::toDateOnly($value)?->toDateString(),
        );
    }

    private static function toDateOnly(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        return Carbon::parse($value)->startOfDay();
    }
}
