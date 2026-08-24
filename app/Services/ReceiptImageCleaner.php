<?php

namespace App\Services;

use App\Models\ReceiptImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 使われていないレシート画像を片付けるサービス。
 *
 * 対象は次の3つ。取引が1件でも紐づいている画像には絶対に触らない。
 *   1. 解析に失敗したまま放置された画像 (status = failed)
 *   2. 家計簿に登録されないまま放置された画像
 *      （status = processed の読み取り済み、および status = pending の読み取り待ち）
 *   3. DB に対応する行が無い孤児ファイル（アップロード直後に落ちた等）
 *
 * 「放置」の判断は作成日時なので、直近のものは猶予期間のあいだ残る。
 * 読み取り待ち(pending)は「アップロードしたが解析していない」だけで、
 * 画面にも出ているし後から読み取れるので、他と同じ猶予にする。
 */
class ReceiptImageCleaner
{
    /** 既定の猶予日数 */
    public const DEFAULT_RETENTION_DAYS = 30;

    /** 画像を置いているディスク上のディレクトリ */
    private const DIRECTORY = 'receipts';

    /**
     * 掃除の対象になるものを数える（削除はしない）。
     *
     * 孤児ファイルの走査はディスク全体を見るので、画面表示など頻繁に呼ぶ場所では
     * `includeOrphanFiles: false` にして DB から分かる分だけ数える。
     *
     * @return array{failed: int, abandoned: int, awaiting: int, orphan_files: int, bytes: int}
     */
    public function preview(int $days = self::DEFAULT_RETENTION_DAYS, bool $includeOrphanFiles = true): array
    {
        $failed = $this->failedRecords($days);
        $abandoned = $this->abandonedRecords($days);
        $orphans = $includeOrphanFiles ? $this->orphanFiles($days) : [];

        $paths = $failed->pluck('path')
            ->concat($abandoned->pluck('path'))
            ->concat($orphans)
            ->filter()
            ->unique();

        // 「読み取り待ちのまま放置されたもの」は画面で別に案内する。
        // 上の青いパネルが「あとで読み取れます」と誘っている画像を、
        // 下の掃除ボタンが黙って消すと驚かせてしまうため。
        $awaiting = $abandoned->where('status', 'pending')->count();

        return [
            'failed' => $failed->count(),
            'abandoned' => $abandoned->count() - $awaiting,
            'awaiting' => $awaiting,
            'orphan_files' => count($orphans),
            'bytes' => $this->totalSize($paths),
        ];
    }

    /**
     * 実際に削除する。
     *
     * @return array{records: int, files: int, bytes: int}
     */
    public function prune(int $days = self::DEFAULT_RETENTION_DAYS): array
    {
        $candidateIds = $this->failedRecords($days)
            ->concat($this->abandonedRecords($days))
            ->pluck('id')
            ->unique()
            ->all();

        $orphans = $this->orphanFiles($days);

        /** @var array<int, string> $paths */
        $paths = [];
        $deletedRecords = 0;

        // 集計から削除までの間に登録されたものを巻き込まないよう、
        // 行ロックを取り直して確認し、DBのレコードを先に消してからファイルを消す。
        // （ファイルを先に消すと、途中で失敗したときに「取引はあるが画像だけ無い」状態が残る）
        if ($candidateIds !== []) {
            DB::transaction(function () use ($candidateIds, &$paths, &$deletedRecords) {
                // 「取引が紐づいていない」の判定もロック読みの WHERE に入れる。
                // あとから1件ずつ isRegistered() を呼ぶと、
                // MySQL(REPEATABLE READ) では直前にコミットされた取引が見えず、
                // 登録されたばかりのレシートを消してしまう。
                $records = ReceiptImage::whereIn('id', $candidateIds)
                    ->whereDoesntHave('transactions')
                    ->lockForUpdate()
                    ->get();

                if ($records->isEmpty()) {
                    return;
                }

                $paths = $records->pluck('path')->filter()->all();
                $deletedRecords = ReceiptImage::whereIn('id', $records->pluck('id')->all())->delete();
            });
        }

        $paths = collect($paths)->concat($orphans)->filter()->unique();

        $bytes = $this->totalSize($paths);
        $deletedFiles = 0;

        foreach ($paths as $path) {
            if ($this->disk()->delete($path)) {
                $deletedFiles++;
            }
        }

        return [
            'records' => $deletedRecords,
            'files' => $deletedFiles,
            'bytes' => $bytes,
        ];
    }

    /**
     * 解析に失敗したまま猶予期間を過ぎたもの。
     *
     * status=failed でも、確認画面から手入力で登録されて取引が紐づいている場合があるので、
     * ここでも必ず「取引が紐づいていないこと」を条件に入れる。
     *
     * @return Collection<int, ReceiptImage>
     */
    private function failedRecords(int $days): Collection
    {
        return ReceiptImage::query()
            ->where('status', 'failed')
            ->whereDoesntHave('transactions')
            ->where('created_at', '<', $this->threshold($days))
            ->get();
    }

    /**
     * 登録されないまま猶予期間を過ぎたもの（読み取り済み・読み取り待ちの両方）。
     *
     * @return Collection<int, ReceiptImage>
     */
    private function abandonedRecords(int $days): Collection
    {
        return ReceiptImage::query()
            ->whereIn('status', ['processed', 'pending'])
            ->whereDoesntHave('transactions')
            ->where('created_at', '<', $this->threshold($days))
            ->get();
    }

    /**
     * DB に対応する行が無いファイル。
     *
     * @return array<int, string>
     */
    private function orphanFiles(int $days): array
    {
        $disk = $this->disk();

        if (! $disk->exists(self::DIRECTORY)) {
            return [];
        }

        $known = ReceiptImage::query()->pluck('path')->filter()->all();
        $threshold = $this->threshold($days)->getTimestamp();

        return collect($disk->files(self::DIRECTORY))
            ->reject(fn (string $path) => in_array($path, $known, true))
            ->filter(function (string $path) use ($disk, $threshold) {
                try {
                    return $disk->lastModified($path) < $threshold;
                } catch (Throwable) {
                    // 取得できないファイルは触らない
                    return false;
                }
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, string>  $paths
     */
    private function totalSize(Collection $paths): int
    {
        $disk = $this->disk();

        return (int) $paths->sum(function (string $path) use ($disk) {
            try {
                return $disk->exists($path) ? $disk->size($path) : 0;
            } catch (Throwable) {
                return 0;
            }
        });
    }

    private function threshold(int $days): Carbon
    {
        return Carbon::now()->subDays(max($days, 0));
    }

    private function disk(): Filesystem
    {
        return Storage::disk('public');
    }
}
