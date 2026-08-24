<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\MerchantCategoryRule;
use App\Models\ReceiptImage;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 家計データのバックアップと復元。
 *
 * 「取ったつもりで戻せない」のが一番まずいので、
 * バックアップ → 全消し → 復元 で元に戻ることを実際に通す。
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->workDir = sys_get_temp_dir().'/kakeibo-backup-test-'.uniqid();
        mkdir($this->workDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);

        parent::tearDown();
    }

    /**
     * 後片付け。glob() だとドットファイルを拾えず、パスに [ ] があると
     * パターン扱いされて何も消せないので scandir を使う。
     */
    private function removeDirectory(string $dir): void
    {
        foreach (array_diff(@scandir($dir) ?: [], ['.', '..']) as $name) {
            $path = $dir.'/'.$name;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function service(): BackupService
    {
        return app(BackupService::class);
    }

    private function seedData(): array
    {
        $food = Category::factory()->create(['name' => '食費']);

        $receipt = ReceiptImage::create([
            'path' => UploadedFile::fake()->image('r.jpg')->store('receipts', 'public'),
            'status' => 'processed',
            'parsed_data' => ['shop_name' => 'スーパーライフ', 'total_amount' => 3200],
        ]);

        // factory の既定は category_id => Category::factory() なので、
        // 指定しないとカテゴリがもう1件増えて件数の検証がずれる
        $recurring = RecurringTransaction::factory()->create([
            'name' => '家賃',
            'amount' => 85000,
            'category_id' => $food->id,
        ]);

        $transaction = Transaction::factory()->create([
            'transaction_date' => '2026-08-01',
            'category_id' => $food->id,
            'receipt_image_id' => $receipt->id,
            'shop_name' => 'スーパーライフ',
            'memo' => null,
            'amount' => 3200,
        ]);

        Budget::factory()->create(['category_id' => $food->id, 'amount' => 50000]);

        // 取引の保存で学習ルールが先に作られている場合があるので updateOrCreate にする
        // （pattern は unique なので create だと衝突しうる）
        MerchantCategoryRule::updateOrCreate(
            ['pattern' => 'スーパーライフ'],
            [
                'display_name' => 'スーパーライフ',
                'category_id' => $food->id,
                'source' => MerchantCategoryRule::SOURCE_LEARNED,
            ],
        );

        return ['food' => $food, 'receipt' => $receipt, 'transaction' => $transaction, 'recurring' => $recurring];
    }

    public function test_バックアップを作ると各テーブルのCSVができる(): void
    {
        $this->seedData();

        $result = $this->service()->create($this->workDir);

        $dir = $this->extractIfNeeded($result['path']);

        $this->assertFileExists($dir.'/manifest.json');

        foreach (BackupService::TABLES as $table) {
            $this->assertFileExists($dir.'/'.$table.'.csv', $table.'.csv がありません');
        }

        $this->assertSame(1, $result['tables']['transactions']);
        $this->assertSame(1, $result['tables']['categories']);
    }

    public function test_manifestに件数と注意書きが入る(): void
    {
        $this->seedData();

        $result = $this->service()->create($this->workDir);
        $dir = $this->extractIfNeeded($result['path']);

        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

        $this->assertSame(1, $manifest['tables']['transactions']);
        $this->assertStringContainsString('user:create', $manifest['note']);
        $this->assertArrayHasKey('generated_at', $manifest);
    }

    public function test_バックアップから復元すると元に戻る(): void
    {
        $seeded = $this->seedData();

        $result = $this->service()->create($this->workDir);
        $dir = $this->extractIfNeeded($result['path']);

        // 全消しする
        Transaction::query()->delete();
        Budget::query()->delete();
        MerchantCategoryRule::query()->delete();
        RecurringTransaction::query()->delete();
        ReceiptImage::query()->delete();
        Category::query()->delete();

        $this->assertSame(0, Transaction::count());

        $restored = $this->service()->restore($dir);

        $this->assertSame(1, $restored['tables']['transactions']);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, Category::count());
        $this->assertSame(1, MerchantCategoryRule::count());

        $transaction = Transaction::first();

        $this->assertSame('スーパーライフ', $transaction->shop_name);
        $this->assertSame(3200, $transaction->amount);
        $this->assertSame('2026-08-01', $transaction->transaction_date->format('Y-m-d'));
        // 外部キーの関係も保たれていること
        $this->assertSame($seeded['food']->id, $transaction->category_id);
        $this->assertSame($seeded['receipt']->id, $transaction->receipt_image_id);
    }

    public function test_nullと空文字を取り違えない(): void
    {
        $category = Category::factory()->create(['name' => '食費']);

        Transaction::factory()->create([
            'category_id' => $category->id,
            'shop_name' => '',
            'memo' => null,
            'amount' => 100,
            'transaction_date' => '2026-08-01',
        ]);

        $result = $this->service()->create($this->workDir);
        $dir = $this->extractIfNeeded($result['path']);

        Transaction::query()->delete();
        Category::query()->delete();

        $this->service()->restore($dir);

        $transaction = Transaction::first();

        $this->assertSame('', $transaction->shop_name);
        $this->assertNull($transaction->memo);
    }

    public function test_画像も含めてバックアップできる(): void
    {
        $this->seedData();

        $result = $this->service()->create($this->workDir, withImages: true);
        $dir = $this->extractIfNeeded($result['path']);

        $this->assertSame(1, $result['images']);
        $this->assertDirectoryExists($dir.'/images');
    }

    public function test_画像も復元される(): void
    {
        $seeded = $this->seedData();
        $path = $seeded['receipt']->path;

        $result = $this->service()->create($this->workDir, withImages: true);
        $dir = $this->extractIfNeeded($result['path']);

        Storage::disk('public')->delete($path);
        Storage::disk('public')->assertMissing($path);

        $this->service()->restore($dir);

        Storage::disk('public')->assertExists($path);
    }

    public function test_壊れたバックアップは復元しない(): void
    {
        $broken = $this->workDir.'/broken';
        mkdir($broken, 0775, true);
        file_put_contents($broken.'/manifest.json', '{}');

        $this->expectException(\RuntimeException::class);

        $this->service()->restore($broken);
    }

    public function test_manifestが無ければ復元しない(): void
    {
        $empty = $this->workDir.'/empty';
        mkdir($empty, 0775, true);

        $this->expectException(\RuntimeException::class);

        $this->service()->restore($empty);
    }

    public function test_artisanコマンドでバックアップできる(): void
    {
        $this->seedData();

        $this->artisan('kakeibo:backup', ['--path' => $this->workDir])->assertExitCode(0);

        $this->assertNotEmpty(glob($this->workDir.'/kakeibo-backup-*'));
    }

    public function test_復元コマンドは確認を求める(): void
    {
        $this->seedData();
        $this->artisan('kakeibo:backup', ['--path' => $this->workDir])->assertExitCode(0);

        $created = glob($this->workDir.'/kakeibo-backup-*');
        $target = collect($created)->first(fn (string $p) => is_dir($p)) ?? $created[0];

        $this->artisan('kakeibo:restore', ['source' => $target])
            ->expectsConfirmation('復元しますか？', 'no')
            ->assertExitCode(1);
    }

    public function test_zipにできたら展開フォルダは残さない(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip が無い環境では zip 化しない');
        }

        $this->seedData();

        $result = $this->service()->create($this->workDir);

        $this->assertTrue($result['zipped']);
        $this->assertStringEndsWith('.zip', $result['path']);
        $this->assertFileExists($result['path']);

        // 同じ中身をフォルダと zip で二重に持たない（週次で回すとディスクが尽きる）
        $leftovers = array_filter(glob($this->workDir.'/kakeibo-backup-*') ?: [], 'is_dir');
        $this->assertSame([], array_values($leftovers));
    }

    public function test_pruneは新しい世代だけ残す(): void
    {
        foreach (['20260101-000000', '20260201-000000', '20260301-000000', '20260401-000000'] as $stamp) {
            touch($this->workDir.'/kakeibo-backup-'.$stamp.'.zip');
        }

        // 無関係のファイルは触らない
        touch($this->workDir.'/メモ.txt');

        $pruned = $this->service()->prune($this->workDir, 2);

        $this->assertCount(2, $pruned['removed']);
        $this->assertSame([], $pruned['broken']);
        $this->assertFileExists($this->workDir.'/kakeibo-backup-20260401-000000.zip');
        $this->assertFileExists($this->workDir.'/kakeibo-backup-20260301-000000.zip');
        $this->assertFileDoesNotExist($this->workDir.'/kakeibo-backup-20260201-000000.zip');
        $this->assertFileDoesNotExist($this->workDir.'/kakeibo-backup-20260101-000000.zip');
        $this->assertFileExists($this->workDir.'/メモ.txt');
    }

    public function test_pruneはフォルダ形式の世代も消せる(): void
    {
        foreach (['20260101-000000', '20260201-000000'] as $stamp) {
            $dir = $this->workDir.'/kakeibo-backup-'.$stamp;
            mkdir($dir.'/images', 0775, true);
            file_put_contents($dir.'/manifest.json', '{}');
            file_put_contents($dir.'/images/a.jpg', 'x');
        }

        $pruned = $this->service()->prune($this->workDir, 1);

        $this->assertCount(1, $pruned['removed']);
        $this->assertDirectoryDoesNotExist($this->workDir.'/kakeibo-backup-20260101-000000');
        $this->assertDirectoryExists($this->workDir.'/kakeibo-backup-20260201-000000');
    }

    public function test_バックアップコマンドはkeepで世代を絞れる(): void
    {
        $this->seedData();

        foreach (['20250101-000000', '20250201-000000'] as $stamp) {
            touch($this->workDir.'/kakeibo-backup-'.$stamp.'.zip');
        }

        $this->artisan('kakeibo:backup', ['--path' => $this->workDir, '--keep' => 1])->assertExitCode(0);

        // いま作ったものだけが残る（古い2つは日付が小さいので消える）
        $this->assertCount(1, glob($this->workDir.'/kakeibo-backup-*') ?: []);
        $this->assertFileDoesNotExist($this->workDir.'/kakeibo-backup-20250101-000000.zip');
        $this->assertFileDoesNotExist($this->workDir.'/kakeibo-backup-20250201-000000.zip');
    }

    public function test_keep0なら世代管理しない(): void
    {
        $this->seedData();

        touch($this->workDir.'/kakeibo-backup-20250101-000000.zip');

        $this->artisan('kakeibo:backup', ['--path' => $this->workDir, '--keep' => 0])->assertExitCode(0);

        $this->assertFileExists($this->workDir.'/kakeibo-backup-20250101-000000.zip');
    }

    public function test_manifestに形式バージョンが入る(): void
    {
        $this->seedData();

        $result = $this->service()->create($this->workDir);
        $dir = $this->extractIfNeeded($result['path']);

        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

        $this->assertSame(BackupService::FORMAT_VERSION, $manifest['version'] ?? null);
    }

    /**
     * @return array<int, string> 往復させたい厄介な値
     */
    public static function 厄介な値(): array
    {
        return [
            'バックスラッシュで終わる' => ['半額シール\\'],
            'バックスラッシュだけ' => ['\\'],
            'Windowsのパス' => ['C:\\temp'],
            'NULLの印と同じ文字列' => ['\N'],
            '逃がしたあとの印と同じ文字列' => ['\\\N'],
            '二重引用符' => ['半"額"シール'],
            '改行' => ["1行目\n2行目"],
            'カンマ' => ['きゅうり,トマト'],
            '絵文字' => ['🍣 寿司'],
            '空文字' => [''],
            '前後の空白' => ['  余白  '],
            'JSONっぽい文字列' => ['{"note":"a\\b"}'],
        ];
    }

    /**
     * バックアップの本番は「壊れたあとに戻すとき」なので、
     * 静かに化ける値が1つでもあってはいけない。
     */
    #[DataProvider('厄介な値')]
    public function test_厄介な値もそのまま戻る(string $memo): void
    {
        $seeded = $this->seedData();
        $seeded['transaction']->forceFill(['memo' => $memo])->save();

        $result = $this->service()->create($this->workDir);

        Transaction::query()->delete();
        $this->assertSame(0, Transaction::query()->count());

        $this->service()->restore($result['path']);

        $this->assertSame($memo, Transaction::query()->first()->memo);
    }

    public function test_件数がmanifestと合わなければ復元せずに元のデータを残す(): void
    {
        $this->seedData();

        $result = $this->service()->create($this->workDir);
        $dir = $this->extractIfNeeded($result['path']);

        // CSV から1行削って「壊れたバックアップ」を作る
        $lines = file($dir.'/transactions.csv', FILE_IGNORE_NEW_LINES);
        file_put_contents($dir.'/transactions.csv', implode("\n", array_slice($lines, 0, 1))."\n");

        $before = Transaction::query()->count();
        $this->assertSame(1, $before);

        try {
            $this->service()->restore($dir);
            $this->fail('壊れたバックアップで復元が通ってしまった');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('manifest', $e->getMessage());
        }

        // 消してから気づく、が一番まずい。ロールバックされていること
        $this->assertSame($before, Transaction::query()->count());
        $this->assertSame(1, Category::query()->count());
    }

    public function test_列が増えていたら何も消さずに止まる(): void
    {
        $this->seedData();

        $result = $this->service()->create($this->workDir);
        $dir = $this->extractIfNeeded($result['path']);

        // バックアップ側から必須列を落とす＝アプリ側に NOT NULL の列が増えた状況。
        // NULL 許容の列は落ちていても復元できるので、そこでは止めない
        $lines = file($dir.'/budgets.csv', FILE_IGNORE_NEW_LINES);
        $header = str_getcsv($lines[0], ',', '"', '');
        $dropped = 'amount';
        $header = array_values(array_filter($header, fn (string $c): bool => $c !== $dropped));
        $lines[0] = implode(',', $header);
        file_put_contents($dir.'/budgets.csv', implode("\n", array_slice($lines, 0, 1))."\n");

        try {
            $this->service()->restore($dir);
            $this->fail('列が足りないバックアップで復元が通ってしまった');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($dropped, $e->getMessage());
        }

        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_新しすぎる形式のバックアップは拒否する(): void
    {
        $this->seedData();

        $result = $this->service()->create($this->workDir);
        $dir = $this->extractIfNeeded($result['path']);

        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        $manifest['version'] = 99;
        file_put_contents($dir.'/manifest.json', json_encode($manifest));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/version 99/');

        $this->service()->restore($dir);
    }

    public function test_画像を含まないバックアップは復元時に知らせる(): void
    {
        $this->seedData();

        $result = $this->service()->create($this->workDir, withImages: false);

        $restored = $this->service()->restore($result['path']);

        $this->assertNotSame([], $restored['warnings']);
        $this->assertStringContainsString('レシート画像', $restored['warnings'][0]);
    }

    public function test_画像を含むバックアップでは警告しない(): void
    {
        $this->seedData();

        $result = $this->service()->create($this->workDir, withImages: true);

        $restored = $this->service()->restore($result['path']);

        $this->assertSame([], $restored['warnings']);
    }

    public function test_中途半端なフォルダは世代に数えない(): void
    {
        // manifest.json が無い＝作成中に落ちた残骸
        mkdir($this->workDir.'/kakeibo-backup-20260101-000000', 0775, true);
        file_put_contents($this->workDir.'/kakeibo-backup-20260101-000000/categories.csv', "id\n");

        touch($this->workDir.'/kakeibo-backup-20260201-000000.zip');
        touch($this->workDir.'/kakeibo-backup-20260301-000000.zip');

        $pruned = $this->service()->prune($this->workDir, 2);

        // 残骸を1世代と数えていたら、有効な zip のどちらかが消えてしまう
        $this->assertSame([], $pruned['removed']);
        $this->assertCount(1, $pruned['broken']);
        $this->assertFileExists($this->workDir.'/kakeibo-backup-20260201-000000.zip');
        $this->assertFileExists($this->workDir.'/kakeibo-backup-20260301-000000.zip');
    }

    public function test_ドットファイルがあっても世代を消しきる(): void
    {
        // 共有フォルダを Mac からも開いていると .DS_Store が落ちてくる。
        // glob() はドットファイルを拾わないので、消し残して rmdir が黙って失敗する。
        $old = $this->workDir.'/kakeibo-backup-20260101-000000';
        mkdir($old.'/images', 0775, true);
        file_put_contents($old.'/manifest.json', '{"version":2}');
        file_put_contents($old.'/.DS_Store', 'x');
        file_put_contents($old.'/images/.DS_Store', 'x');

        touch($this->workDir.'/kakeibo-backup-20260301-000000.zip');

        $pruned = $this->service()->prune($this->workDir, 1);

        $this->assertSame([$old], $pruned['removed']);
        $this->assertDirectoryDoesNotExist($old);
    }

    public function test_パスに角括弧があっても世代管理が効く(): void
    {
        // glob() は [ ] をパターンとして解釈するので、この置き場所だと黙って何もしなくなる
        $dir = $this->workDir.'/share[2026]';
        mkdir($dir, 0775, true);

        touch($dir.'/kakeibo-backup-20260101-000000.zip');
        touch($dir.'/kakeibo-backup-20260201-000000.zip');

        $pruned = $this->service()->prune($dir, 1);

        $this->assertCount(1, $pruned['removed']);
        $this->assertFileDoesNotExist($dir.'/kakeibo-backup-20260101-000000.zip');
        $this->assertFileExists($dir.'/kakeibo-backup-20260201-000000.zip');
    }

    /**
     * zip なら展開してフォルダを返す。フォルダならそのまま。
     */
    private function extractIfNeeded(string $path): string
    {
        if (is_dir($path)) {
            return $path;
        }

        $to = $this->workDir.'/extracted-'.uniqid();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $zip->extractTo($to);
        $zip->close();

        $inner = glob($to.'/*', GLOB_ONLYDIR) ?: [];

        return $inner === [] ? $to : $inner[0];
    }

    public function test_NULL許容の列が増えていても復元できる(): void
    {
        $this->seedData();

        $result = $this->service()->create($this->workDir);
        $dir = $this->extractIfNeeded($result['path']);

        // memo は NULL 許容なので、古いバックアップに無くても復元できてよい
        $lines = file($dir.'/budgets.csv', FILE_IGNORE_NEW_LINES);
        $header = str_getcsv($lines[0], ',', '"', '');
        $memoIndex = array_search('memo', $header, true);

        $this->assertNotFalse($memoIndex, 'budgets.csv に memo 列がありません');

        $rewritten = [];

        foreach ($lines as $line) {
            $values = str_getcsv($line, ',', '"', '');
            unset($values[$memoIndex]);
            $rewritten[] = implode(',', array_values($values));
        }

        file_put_contents($dir.'/budgets.csv', implode("\n", $rewritten)."\n");

        $restored = $this->service()->restore($dir);

        $this->assertSame(1, $restored['tables']['budgets']);
    }

    public function test_取込バッチもバックアップに含まれる(): void
    {
        $this->assertContains('import_batches', BackupService::TABLES);

        // transactions.import_batch_id の参照先なので、必ず先に入っていること
        $order = array_flip(BackupService::TABLES);
        $this->assertLessThan($order['transactions'], $order['import_batches']);

        // 覚えた列の対応も、復元したら戻っていてほしい
        $this->assertContains('import_profiles', BackupService::TABLES);
        $this->assertContains('import_ignore_rules', BackupService::TABLES);
    }

    public function test_テーブルが増える前のバックアップも復元できる(): void
    {
        $this->seedData();

        $result = $this->service()->create($this->workDir);
        $dir = $this->extractIfNeeded($result['path']);

        // 「このテーブルが無かった頃のバックアップ」を作る
        unlink($dir.'/import_ignore_rules.csv');

        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        unset($manifest['tables']['import_ignore_rules']);
        file_put_contents($dir.'/manifest.json', json_encode($manifest));

        $restored = $this->service()->restore($dir);

        // テーブルを1つ足しただけで過去のバックアップが全滅してはいけない
        $this->assertSame(0, $restored['tables']['import_ignore_rules']);
        $this->assertSame(1, $restored['tables']['transactions']);
    }

    public function test_manifestにあるのにCSVが無ければ壊れている扱いにする(): void
    {
        $this->seedData();

        $result = $this->service()->create($this->workDir);
        $dir = $this->extractIfNeeded($result['path']);

        unlink($dir.'/transactions.csv');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/transactions\.csv/');

        $this->service()->restore($dir);
    }
}
