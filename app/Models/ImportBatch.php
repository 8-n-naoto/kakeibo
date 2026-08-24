<?php

namespace App\Models;

use App\Models\Concerns\StoresDateOnly;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CSV取込1回ぶんの記録。
 *
 * 取込を間違えたときに「この取込をまとめて取り消す」ためだけに存在する。
 * 取り消したあとも記録自体は消さず、いつ何を取り込んだかの履歴として残す。
 */
class ImportBatch extends Model
{
    use HasFactory;
    use StoresDateOnly;

    protected $fillable = [
        'file_name',
        'source',
        'row_count',
        'total_amount',
        'first_date',
        'last_date',
    ];

    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'total_amount' => 'integer',
        ];
    }

    /** 日付のみを保存する(MySQL/SQLiteで同じ値になるようにする) */
    protected function firstDate(): Attribute
    {
        return static::dateOnlyAttribute();
    }

    /** 日付のみを保存する(MySQL/SQLiteで同じ値になるようにする) */
    protected function lastDate(): Attribute
    {
        return static::dateOnlyAttribute();
    }

    protected static function booted(): void
    {
        // SQLite では Schema::table で後から足した外部キーが効かないため、
        // モデル側でも紐付けを外しておく（テストと本番で挙動を揃える）
        static::deleting(function (self $batch) {
            // toBase() で updated_at を触らない。
            // 触ると「取込後に手で直した行」の判定（updated_at > created_at）が狂う
            Transaction::where('import_batch_id', $batch->id)
                ->toBase()
                ->update(['import_batch_id' => null]);
        });
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * 取り消せる（＝まだ1件でも残っていて、手で編集されていない）取引。
     *
     * 取り込んだあとに手で直した行は、取り消しの巻き添えで消さない。
     * 直したということは、その行にはもう人の判断が入っている。
     */
    public function revertableTransactions(): HasMany
    {
        return $this->transactions()->whereColumn('updated_at', '<=', 'created_at');
    }

    /** 取り込んだあとに手で直された取引（取り消しても残す） */
    public function editedTransactions(): HasMany
    {
        return $this->transactions()->whereColumn('updated_at', '>', 'created_at');
    }

    public function getPeriodLabelAttribute(): string
    {
        if ($this->first_date === null || $this->last_date === null) {
            return '—';
        }

        if ($this->first_date->equalTo($this->last_date)) {
            return $this->first_date->format('Y/n/j');
        }

        return $this->first_date->format('Y/n/j').'〜'.$this->last_date->format('Y/n/j');
    }
}
