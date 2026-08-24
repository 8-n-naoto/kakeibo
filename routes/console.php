<?php

use App\Models\User;
use App\Services\BackupService;
use App\Services\ReceiptImageCleaner;
use App\Services\RecurringTransactionService;
use App\Support\MonthParser;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * 定期支出（家賃・サブスクなど）の当月分を取引として計上する。
 *
 *   php artisan recurring:post
 *   php artisan recurring:post --month=2026-08
 *
 * すでに計上済みのものはスキップするので、何度実行しても二重計上にならない。
 * cron で `php artisan schedule:run` を回していない環境では、
 * 「定期支出」画面のボタンから同じ処理を実行できる。
 */
Artisan::command('recurring:post {--month= : 対象月(YYYY-MM)。省略すると当月}', function (RecurringTransactionService $service) {
    $input = $this->option('month');

    if ($input === null || $input === '') {
        $month = Carbon::now()->startOfMonth();
    } else {
        // 画面と同じ判定を使う（"2026-13" のような範囲外も弾く）
        $month = MonthParser::parse($input);

        if ($month === null) {
            $this->error('月の指定が不正です。YYYY-MM の形式で指定してください（例: --month=2026-08）。');

            return 1;
        }
    }

    $result = $service->post($month);

    $this->info(sprintf(
        '%s: %d件を計上しました（スキップ %d件）',
        $month->format('Y-m'),
        $result['created'],
        $result['skipped'],
    ));

    if (($result['mismatched'] ?? 0) > 0) {
        $this->warn(sprintf(
            '%d件は、定期支出の種別とカテゴリの種別が合っていなかったため未分類で計上しました。'
                .'定期支出の設定を見直してください。',
            $result['mismatched'],
        ));
    }

    return 0;
})->purpose('定期支出の指定月分を取引として計上する');

/**
 * ログイン用のユーザーを作る（すでに同じメールがあればパスワードを上書きする）。
 *
 *   php artisan user:create you@example.com
 *   php artisan user:create you@example.com --name=にいと --password=xxxxxxxxxxxx
 *
 * パスワードは 12文字以上・72バイト以内（bcrypt が72バイトで切り捨てるため）。
 *
 * --password を省略すると入力を求められる（画面には表示されない）。
 * このアプリは単一ユーザー前提なので、基本は1つ作れば十分。
 */
Artisan::command('user:create {email : ログインに使うメールアドレス} {--name= : 表示名} {--password= : パスワード(省略時は対話入力)}', function () {
    $email = (string) $this->argument('email');

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('メールアドレスの形式が正しくありません: '.$email);

        return 1;
    }

    $password = (string) ($this->option('password') ?: $this->secret('パスワード（12文字以上）'));

    if (mb_strlen($password) < 12) {
        $this->error('パスワードは12文字以上にしてください。');

        return 1;
    }

    // bcrypt は72バイトを超えた分を黙って捨てる。日本語（1文字3バイト）だと
    // 24文字でちょうど72バイトなので、長いパスフレーズは後半が効いていない。
    // 「長くしたのに強くなっていない」状態を作らないよう、ここで止める。
    if (strlen($password) > 72) {
        $this->error(sprintf(
            'パスワードは72バイトまでです（いまは%dバイト）。'
                .'bcrypt が72バイトで切り捨てるため、それ以上は強度になりません。'
                .'日本語なら24文字までが目安です。',
            strlen($password),
        ));

        return 1;
    }

    $user = User::updateOrCreate(
        ['email' => $email],
        [
            // password は User モデルの hashed キャストで自動的にハッシュ化される
            'name' => (string) ($this->option('name') ?: '家計簿ユーザー'),
            'password' => $password,
        ],
    );

    $this->info(($user->wasRecentlyCreated ? '作成しました' : 'パスワードを更新しました').': '.$user->email);

    return 0;
})->purpose('ログイン用のユーザーを作成・更新する');

/**
 * 使われていないレシート画像を片付ける。
 *
 *   php artisan receipts:prune               # 30日より前のものが対象
 *   php artisan receipts:prune --days=90
 *   php artisan receipts:prune --dry-run     # 数えるだけ
 *
 * 取引が1件でも紐づいている画像には触らない。
 */
Artisan::command('receipts:prune {--days=30 : 何日より前のものを対象にするか} {--dry-run : 削除せず件数だけ表示する}', function (ReceiptImageCleaner $cleaner) {
    $days = (int) $this->option('days');

    if ($days < 1) {
        // 0 を許すと、いままさに解析中のレコードやアップロード直後のファイルを巻き込む
        $this->error('--days には1以上を指定してください。');

        return 1;
    }

    if ($this->option('dry-run')) {
        $preview = $cleaner->preview($days);

        $this->info(sprintf(
            '対象: 読み取り失敗 %d件 / 読み取り済みで未登録 %d件 / 読み取り待ちのまま放置 %d件 / DBに無いファイル %d件 (合計 %s)',
            $preview['failed'],
            $preview['abandoned'],
            $preview['awaiting'],
            $preview['orphan_files'],
            number_format($preview['bytes']).' bytes',
        ));

        return 0;
    }

    $result = $cleaner->prune($days);

    $this->info(sprintf(
        'レコード %d件 / ファイル %d件 を削除しました (%s)',
        $result['records'],
        $result['files'],
        number_format($result['bytes']).' bytes',
    ));

    return 0;
})->purpose('使われていないレシート画像を削除する');

/**
 * 家計データをバックアップする。
 *
 *   php artisan kakeibo:backup                       # storage/app/backups へ
 *   php artisan kakeibo:backup --path=/mnt/share     # 置き場所を指定
 *   php artisan kakeibo:backup --with-images         # レシート画像も含める（サイズが大きい）
 *   php artisan kakeibo:backup --keep=4              # 残す世代数（既定8、0で世代管理しない）
 *
 * テーブルごとの CSV と manifest.json を1つのフォルダにまとめ、zip があれば固める。
 * CSV にしているのは、復元コマンドが動かなくなっても人が読めて手で戻せるようにするため。
 * users テーブルは含めない（復元後に user:create でアカウントを作り直す）。
 */
Artisan::command('kakeibo:backup {--path= : 置き場所（省略時は storage/app/backups）} {--with-images : レシート画像も含める} {--keep= : 残す世代数（省略時は8、0で世代管理しない）}', function (BackupService $backup) {
    $path = (string) ($this->option('path') ?: storage_path('app/backups'));

    if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
        $this->error('置き場所を作れませんでした: '.$path);

        return 1;
    }

    $result = $backup->create($path, (bool) $this->option('with-images'));

    $this->info('バックアップを作成しました: '.$result['path']);

    foreach ($result['tables'] as $table => $rows) {
        $this->line(sprintf('  %-26s %6d 行', $table, $rows));
    }

    if ($result['images'] > 0) {
        $this->line(sprintf('  %-26s %6d 枚', 'レシート画像', $result['images']));
    }

    if (! $result['zipped']) {
        $this->warn('ext-zip が無いため zip にできませんでした。フォルダのまま保管してください。');
    }

    // 世代管理。週次で回しっぱなしにすると VM のディスクが先に尽きるため既定で残す。
    $keepOption = $this->option('keep');
    $keep = $keepOption === null ? BackupService::DEFAULT_KEEP : (int) $keepOption;

    if ($keep > 0) {
        $pruned = $backup->prune($path, $keep);

        foreach ($pruned['removed'] as $old) {
            $this->line('  古い世代を削除: '.basename((string) $old));
        }

        foreach ($pruned['broken'] as $brokenPath) {
            $this->warn('  中途半端なバックアップが残っています（手で消してください）: '.basename((string) $brokenPath));
        }
    }

    return 0;
})->purpose('家計データをCSVでバックアップする');

/**
 * バックアップから復元する。**既存のデータはすべて置き換わる。**
 *
 *   php artisan kakeibo:restore /mnt/share/kakeibo-backup-20260824-030000.zip
 *
 * 先に空のDBで一度通しておくこと。「取ったつもりで戻せない」のが一番まずい。
 */
Artisan::command('kakeibo:restore {source : バックアップの zip かフォルダ} {--force : 確認をとばす} {--without-images : 画像は復元しない}', function (BackupService $backup) {
    $source = (string) $this->argument('source');

    $this->warn('いまDBに入っている家計データはすべて置き換わります。');

    if (! $this->option('force') && ! $this->confirm('復元しますか？', false)) {
        $this->line('中止しました。');

        return 1;
    }

    $result = $backup->restore($source, ! $this->option('without-images'));

    $this->info('復元しました。');

    foreach ($result['tables'] as $table => $rows) {
        $this->line(sprintf('  %-26s %6d 行', $table, $rows));
    }

    if ($result['images'] > 0) {
        $this->line(sprintf('  %-26s %6d 枚', 'レシート画像', $result['images']));
    }

    foreach ($result['warnings'] as $warning) {
        $this->warn($warning);
    }

    $this->warn('ログイン用のアカウントは含まれていません。php artisan user:create で作り直してください。');

    return 0;
})->purpose('バックアップから家計データを復元する');

// 毎月1日の03:00に当月分を自動計上する（cron で schedule:run を回している場合のみ有効）
Schedule::command('recurring:post')->monthlyOn(1, '03:00');

// 毎週日曜の04:00に古いレシート画像を片付ける（同上）
Schedule::command('receipts:prune')->weeklyOn(0, '04:00');

// 毎週日曜の04:30にバックアップを作る（同上）。画像は容量が大きいので既定では含めない。
// 8世代（約2か月分）だけ残す。無制限に貯めると VM のディスクが先に尽きる。
Schedule::command('kakeibo:backup')->weeklyOn(0, '04:30')->withoutOverlapping();
