<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * 家計データのバックアップと復元。
 *
 * 家計簿は積み上げた年数がそのまま価値になるのに、実体は VM の MySQL と
 * storage/app/public/receipts にしか無い。VM が壊れたら全部消える。
 *
 * バックアップは「1つのフォルダ（または zip）に、テーブルごとの CSV と manifest.json」。
 * CSV にしているのは、復元コマンドが動かなくなっても人が読めて手で戻せるようにするため。
 *
 * users テーブルは含めない。パスワードハッシュを共有フォルダに置きたくないのと、
 * アカウントは `php artisan user:create` ですぐ作り直せるため。
 *
 * ■ CSV の書式（FORMAT_VERSION = 2）
 *   - RFC4180 準拠。エスケープ文字は使わない（fputcsv/fgetcsv の $escape に '' を渡す）。
 *     PHP 既定の '\' を使うと「末尾がバックスラッシュの値」（例: メモに `半額シール\`）で
 *     閉じ引用符がエスケープされてしまい、以降の列と行を丸ごと飲み込む。静かに壊れる。
 *   - NULL は `\N`。空文字と区別するため。実データが `\N` `\\N` … だった場合は
 *     バックスラッシュを1つ足して逃がす。
 *   - version 1 は $escape に '\' を使っていた。manifest の version を見て読み分ける。
 */
class BackupService
{
    /**
     * バックアップ対象のテーブル。
     * 復元はこの順に入れる（親→子）ので、並び順に意味がある。
     * 削除はこの逆順（子→親）なので、外部キーを切らなくても通る。
     */
    public const TABLES = [
        'categories',
        'receipt_images',
        'recurring_transactions',
        'import_batches',
        'transactions',
        'budgets',
        'asset_snapshots',
        'savings_goals',
        'investment_accounts',
        'merchant_category_rules',
        'import_profiles',
        'import_ignore_rules',
    ];

    /** レシート画像の置き場所（public ディスク上） */
    private const IMAGE_DIRECTORY = 'receipts';

    /** 1回に読み書きする行数 */
    private const CHUNK = 500;

    /** manifest.json の形式バージョン。CSV の書式を変えたら上げる。 */
    public const FORMAT_VERSION = 2;

    /** NULL を表す印。空文字と区別するために使う。 */
    private const NULL_SENTINEL = '\N';

    /** 世代管理をしないと VM のディスクが週次バックアップで埋まる */
    public const DEFAULT_KEEP = 8;

    /** バックアップフォルダ／zip の名前の頭 */
    private const PREFIX = 'kakeibo-backup-';

    /** restore() が zip を展開した先。後始末のために覚えておく。 */
    private ?string $temporaryDir = null;

    /**
     * バックアップを作る。
     *
     * @return array{path: string, tables: array<string, int>, images: int, zipped: bool}
     */
    public function create(string $destination, bool $withImages = false): array
    {
        $workDir = rtrim($destination, '/').'/'.self::PREFIX.Carbon::now()->format('Ymd-His');

        if (! is_dir($workDir) && ! mkdir($workDir, 0775, true) && ! is_dir($workDir)) {
            throw new RuntimeException('バックアップ先を作れませんでした: '.$workDir);
        }

        try {
            $counts = [];

            foreach (self::TABLES as $table) {
                $counts[$table] = $this->dumpTable($table, $workDir.'/'.$table.'.csv');
            }

            $images = $withImages ? $this->copyImages($workDir.'/images') : 0;

            $manifest = json_encode([
                'version' => self::FORMAT_VERSION,
                'generated_at' => Carbon::now()->toIso8601String(),
                'app' => config('app.name'),
                'tables' => $counts,
                'images' => $images,
                'with_images' => $withImages,
                'note' => 'users テーブルは含みません。復元後に php artisan user:create でアカウントを作り直してください。',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (file_put_contents($workDir.'/manifest.json', $manifest) === false) {
                throw new RuntimeException('manifest.json を書けませんでした: '.$workDir);
            }

            $zipPath = $this->zip($workDir);
        } catch (Throwable $e) {
            // 途中で落ちた中途半端なフォルダを残さない。
            // 残すと prune() が「1世代」と数えてしまい、実際に戻せる世代がその分減る。
            $this->removeDirectory($workDir);

            throw $e;
        }

        if ($zipPath !== null) {
            // zip にできたら展開済みフォルダは残さない。
            // 週次で回すと同じ中身を二重に持つことになり、VM のディスクが先に尽きる。
            $this->removeDirectory($workDir);
        }

        return [
            'path' => $zipPath ?? $workDir,
            'tables' => $counts,
            'images' => $images,
            'zipped' => $zipPath !== null,
        ];
    }

    /**
     * バックアップから復元する。**既存のデータはすべて置き換わる。**
     *
     * @return array{tables: array<string, int>, images: int, warnings: array<int, string>}
     */
    public function restore(string $source, bool $withImages = true): array
    {
        try {
            return $this->restoreFrom($this->resolveSource($source), $source, $withImages);
        } finally {
            // zip を展開した一時フォルダを片付ける（成功しても失敗しても）
            if ($this->temporaryDir !== null) {
                $this->removeDirectory($this->temporaryDir);
                $this->temporaryDir = null;
            }
        }
    }

    /**
     * @return array{tables: array<string, int>, images: int, warnings: array<int, string>}
     */
    private function restoreFrom(string $dir, string $source, bool $withImages): array
    {
        if (! is_file($dir.'/manifest.json')) {
            throw new RuntimeException('manifest.json が見つかりません。バックアップのフォルダか zip を指定してください: '.$source);
        }

        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

        if (! is_array($manifest)) {
            throw new RuntimeException('manifest.json を読めませんでした。バックアップが壊れている可能性があります: '.$source);
        }

        $version = (int) ($manifest['version'] ?? 1);

        if ($version > self::FORMAT_VERSION) {
            throw new RuntimeException(sprintf(
                'このバックアップは新しい形式（version %d）です。アプリを更新してから復元してください。',
                $version,
            ));
        }

        // tables が無い manifest は「空のバックアップ」ではなく、書き出しの途中で
        // 落ちた残骸か別物。ここを通すと**全テーブルを消して0件で戻す**ことになる。
        if (! isset($manifest['tables']) || ! is_array($manifest['tables'])) {
            throw new RuntimeException(
                'manifest.json にテーブルの一覧がありません。バックアップが壊れている可能性があります: '.$source,
            );
        }

        // version 1 は fputcsv の既定エスケープ（\）で書かれている
        $escape = $version >= 2 ? '' : '\\';

        // manifest に載っているテーブルの CSV が無ければ壊れている。
        // 載っていないテーブル（バックアップを取ったあとに増えたもの）は、
        // 空だったものとして扱う。ここで止めると、**テーブルを1つ足しただけで
        // 過去のバックアップがすべて復元不能になる**。
        $dumped = (array) ($manifest['tables'] ?? []);

        foreach (self::TABLES as $table) {
            if (! is_file($dir.'/'.$table.'.csv') && array_key_exists($table, $dumped)) {
                throw new RuntimeException($table.'.csv が見つかりません。バックアップが壊れている可能性があります。');
            }
        }

        $this->assertColumnsPresent($dir, $escape);

        $counts = [];

        DB::transaction(function () use ($dir, $escape, $manifest, &$counts) {
            foreach (array_reverse(self::TABLES) as $table) {
                DB::table($table)->delete();
            }

            foreach (self::TABLES as $table) {
                $path = $dir.'/'.$table.'.csv';
                // バックアップを取ったあとに増えたテーブルは、空だったものとして扱う
                $counts[$table] = is_file($path) ? $this->loadTable($table, $path, $escape) : 0;
            }

            // manifest の件数と突き合わせる。ここで throw すれば削除ごとロールバックされるので、
            // 壊れたバックアップを掴んでも「消えただけ」にはならない。
            foreach (self::TABLES as $table) {
                $expected = $manifest['tables'][$table] ?? null;

                if (is_int($expected) && $expected !== $counts[$table]) {
                    throw new RuntimeException(sprintf(
                        '%s: manifest は %d 行ですが %d 行しか復元できませんでした。バックアップが壊れています（復元は取り消しました）。',
                        $table,
                        $expected,
                        $counts[$table],
                    ));
                }
            }
        });

        // カテゴリと学習ルールを丸ごと入れ替えたので、リクエスト内で覚えている
        // 一覧（CategoryIdIndex / MerchantCategoryGuesser / TransactionObserver）を捨てる。
        // これらは scoped なので、まとめて忘れさせられる。
        app()->forgetScopedInstances();

        $warnings = [];
        $hasImageDir = is_dir($dir.'/images');

        if (($manifest['with_images'] ?? false) === false || ! $hasImageDir) {
            $warnings[] = 'このバックアップにレシート画像は含まれていません（取引データは復元されますが、レシート画像は表示できません）。';
        }

        $images = ($withImages && $hasImageDir) ? $this->restoreImages($dir.'/images') : 0;

        return ['tables' => $counts, 'images' => $images, 'warnings' => $warnings];
    }

    /**
     * 復元前に、いまのテーブルに「CSV に無い NOT NULL 列」が増えていないかを見る。
     * 何も消さないうちに日本語で止めたいので、トランザクションの外でやる。
     */
    private function assertColumnsPresent(string $dir, string $escape): void
    {
        foreach (self::TABLES as $table) {
            if (! is_file($dir.'/'.$table.'.csv')) {
                continue;
            }

            $handle = fopen($dir.'/'.$table.'.csv', 'rb');

            if ($handle === false) {
                throw new RuntimeException('読み込めませんでした: '.$dir.'/'.$table.'.csv');
            }

            $header = fgetcsv($handle, 0, ',', '"', $escape);
            fclose($handle);

            if (! is_array($header)) {
                throw new RuntimeException($table.'.csv が空です。バックアップが壊れている可能性があります。');
            }

            $present = array_filter($header, 'is_string');
            $missing = array_diff($this->requiredColumns($table), $present);

            if ($missing !== []) {
                throw new RuntimeException(sprintf(
                    '%s: バックアップに %s の列がありません。バックアップを取ったあとにテーブルが変わっています。'
                    .'先に新しいバックアップを取るか、CSV に列を足してから復元してください。',
                    $table,
                    implode('、', $missing),
                ));
            }
        }
    }

    /**
     * 「バックアップに無いと復元できない列」だけを返す。
     *
     * 全カラムを必須にすると、あとから NULL 許容の列を1つ足しただけで
     * それ以前のバックアップが**すべて復元不能**になる。
     * 実際に INSERT が通らないのは「NOT NULL かつ既定値なし」の列だけなので、そこだけ見る。
     *
     * @return array<int, string>
     */
    private function requiredColumns(string $table): array
    {
        $required = [];

        foreach (Schema::getColumns($table) as $column) {
            if (($column['nullable'] ?? true) === true) {
                continue;
            }

            if (($column['default'] ?? null) !== null || ($column['auto_increment'] ?? false) === true) {
                continue;
            }

            $required[] = $column['name'];
        }

        return $required;
    }

    /**
     * テーブル1つを CSV に書き出す。1行目はカラム名。
     */
    private function dumpTable(string $table, string $path): int
    {
        $columns = Schema::getColumnListing($table);
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('書き出せませんでした: '.$path);
        }

        try {
            fputcsv($handle, $columns, ',', '"', '');

            $rows = 0;

            DB::table($table)->orderBy('id')->chunk(self::CHUNK, function ($chunk) use ($handle, $columns, &$rows) {
                foreach ($chunk as $row) {
                    $values = [];

                    foreach ($columns as $column) {
                        $value = $row->{$column} ?? null;

                        if ($value === null) {
                            $values[] = self::NULL_SENTINEL;

                            continue;
                        }

                        $string = (string) $value;

                        // 実データが "\N" だった場合に NULL と取り違えないよう1つ逃がす
                        $values[] = self::looksLikeSentinel($string) ? '\\'.$string : $string;
                    }

                    if (fputcsv($handle, $values, ',', '"', '') === false) {
                        throw new RuntimeException('書き出しに失敗しました（ディスクの空きを確認してください）: '.$table);
                    }

                    $rows++;
                }
            });
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * CSV からテーブル1つに読み込む。
     */
    private function loadTable(string $table, string $path, string $escape): int
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('読み込めませんでした: '.$path);
        }

        try {
            $columns = fgetcsv($handle, 0, ',', '"', $escape);

            if (! is_array($columns)) {
                return 0;
            }

            $existing = Schema::getColumnListing($table);
            $buffer = [];
            $rows = 0;
            $line = 1;

            while (($values = fgetcsv($handle, 0, ',', '"', $escape)) !== false) {
                $line++;

                if ($values === [null]) {
                    continue;
                }

                if (count($values) !== count($columns)) {
                    throw new RuntimeException(sprintf(
                        '%s.csv の %d 行目の列数が合いません（%d 列のはずが %d 列）。バックアップが壊れています。',
                        $table,
                        $line,
                        count($columns),
                        count($values),
                    ));
                }

                $record = [];

                foreach ($columns as $index => $column) {
                    // バックアップ後にカラムを消した場合に備えて、いま無い列は捨てる
                    if (! in_array($column, $existing, true)) {
                        continue;
                    }

                    $record[$column] = self::decode($values[$index] ?? null);
                }

                if ($record === []) {
                    continue;
                }

                $buffer[] = $record;
                $rows++;

                if (count($buffer) >= self::CHUNK) {
                    DB::table($table)->insert($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                DB::table($table)->insert($buffer);
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * `\N` は NULL。`\\N` `\\\N` … は逃がした結果なので1つ戻す。
     */
    private static function decode(?string $value): ?string
    {
        if ($value === null || $value === self::NULL_SENTINEL) {
            return null;
        }

        return self::looksLikeSentinel($value) ? substr($value, 1) : $value;
    }

    /**
     * バックスラッシュ1つ以上のあとに N だけ、という形か。
     */
    private static function looksLikeSentinel(string $value): bool
    {
        return preg_match('/^\\\\+N$/', $value) === 1;
    }

    private function copyImages(string $destination): int
    {
        $disk = Storage::disk('public');

        if (! $disk->exists(self::IMAGE_DIRECTORY)) {
            return 0;
        }

        if (! is_dir($destination) && ! mkdir($destination, 0775, true) && ! is_dir($destination)) {
            throw new RuntimeException('画像の書き出し先を作れませんでした: '.$destination);
        }

        $copied = 0;

        foreach ($disk->files(self::IMAGE_DIRECTORY) as $file) {
            if (copy($disk->path($file), $destination.'/'.basename($file))) {
                $copied++;
            }
        }

        return $copied;
    }

    private function restoreImages(string $source): int
    {
        $disk = Storage::disk('public');
        $restored = 0;

        foreach ($this->entriesIn($source) as $file) {
            if (! is_file($file)) {
                continue;
            }

            $disk->put(self::IMAGE_DIRECTORY.'/'.basename($file), (string) file_get_contents($file));
            $restored++;
        }

        return $restored;
    }

    /**
     * zip があれば固める。ext-zip が無い環境や書き込みに失敗した場合はフォルダのまま残す。
     */
    private function zip(string $dir): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $zipPath = $dir.'.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $base = basename($dir);
        $files = $this->filesUnder($dir);

        foreach ($files as $file) {
            $zip->addFile($file, $base.'/'.ltrim(str_replace($dir, '', $file), '/'));
        }

        // ZipArchive は close() のときに実際に書く。ディスクが一杯なら
        // ここで false が返る。無視するとフォルダを消した直後に空の zip だけが残る。
        if (! $zip->close()) {
            @unlink($zipPath);

            return null;
        }

        if (! is_file($zipPath) || filesize($zipPath) === 0) {
            @unlink($zipPath);

            return null;
        }

        // 中身の数まで確かめてから、元フォルダを消す判断をする
        $check = new ZipArchive();

        if ($check->open($zipPath) !== true) {
            @unlink($zipPath);

            return null;
        }

        $numFiles = $check->numFiles;
        $check->close();

        if ($numFiles !== count($files)) {
            @unlink($zipPath);

            return null;
        }

        return $zipPath;
    }

    /**
     * zip なら展開して、その中の manifest.json があるフォルダを返す。フォルダならそのまま。
     */
    private function resolveSource(string $source): string
    {
        if (is_dir($source)) {
            return rtrim($source, '/');
        }

        if (! is_file($source)) {
            throw new RuntimeException('バックアップが見つかりません: '.$source);
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('zip を展開できません（ext-zip が無い）。手で展開してからフォルダを指定してください。');
        }

        $zip = new ZipArchive();

        if ($zip->open($source) !== true) {
            throw new RuntimeException('zip を開けませんでした: '.$source);
        }

        $extractTo = sys_get_temp_dir().'/kakeibo-restore-'.uniqid();
        $this->temporaryDir = $extractTo;
        $zip->extractTo($extractTo);
        $zip->close();

        if (is_file($extractTo.'/manifest.json')) {
            return $extractTo;
        }

        // 手で固め直した zip には __MACOSX/ などが混ざる。名前順の先頭ではなく、
        // manifest.json を持っているフォルダを選ぶ。
        foreach ($this->entriesIn($extractTo) as $candidate) {
            if (is_dir($candidate) && is_file($candidate.'/manifest.json')) {
                return $candidate;
            }
        }

        return $extractTo;
    }

    /**
     * 古い世代を消して、最新 $keep 個だけ残す。
     * 週次スケジュールで作りっぱなしにすると VM のディスクが尽きるため。
     *
     * 「世代」と数えるのは zip ファイルか、manifest.json を持つフォルダだけ。
     * 途中で落ちた中途半端なフォルダを1世代と数えて、戻せる世代を減らさないようにする。
     *
     * @return array{removed: array<int, string>, broken: array<int, string>}
     */
    public function prune(string $destination, int $keep = self::DEFAULT_KEEP): array
    {
        $keep = max(1, $keep);
        $generations = [];
        $broken = [];

        foreach ($this->entriesIn(rtrim($destination, '/')) as $entry) {
            if (! str_starts_with(basename($entry), self::PREFIX)) {
                continue;
            }

            if (is_dir($entry) && ! is_file($entry.'/manifest.json')) {
                $broken[] = $entry;

                continue;
            }

            $generations[] = $entry;
        }

        // 名前に作成時刻が入っているので、名前順＝新しい順（降順）で並べられる
        rsort($generations, SORT_STRING);

        $removed = [];

        foreach (array_slice($generations, $keep) as $entry) {
            if (is_dir($entry)) {
                if ($this->removeDirectory($entry)) {
                    $removed[] = $entry;
                }

                continue;
            }

            if (is_file($entry) && @unlink($entry)) {
                $removed[] = $entry;
            }
        }

        return ['removed' => $removed, 'broken' => $broken];
    }

    /**
     * フォルダを中身ごと消す。消しきれたかを返す。
     */
    private function removeDirectory(string $dir): bool
    {
        if (! is_dir($dir)) {
            return false;
        }

        $ok = true;

        foreach ($this->entriesIn($dir) as $path) {
            if (is_dir($path)) {
                $ok = $this->removeDirectory($path) && $ok;

                continue;
            }

            $ok = @unlink($path) && $ok;
        }

        return @rmdir($dir) && $ok;
    }

    /**
     * @return array<int, string>
     */
    private function filesUnder(string $dir): array
    {
        $found = [];

        foreach ($this->entriesIn($dir) as $path) {
            if (is_dir($path)) {
                $found = array_merge($found, $this->filesUnder($path));

                continue;
            }

            $found[] = $path;
        }

        return $found;
    }

    /**
     * フォルダ直下の中身。glob() と違い、ドットファイル（.DS_Store など）も拾い、
     * パスに [ や * が含まれていてもパターンとして解釈されない。
     *
     * @return array<int, string>
     */
    private function entriesIn(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $names = @scandir($dir);

        if ($names === false) {
            return [];
        }

        $entries = [];

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $entries[] = $dir.'/'.$name;
        }

        sort($entries, SORT_STRING);

        return $entries;
    }
}
