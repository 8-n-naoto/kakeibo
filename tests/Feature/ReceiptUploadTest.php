<?php

namespace Tests\Feature;

use App\Models\ReceiptImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * レシート画像のアップロード。
 *
 * アップロードは「保存するだけ」で AI を呼ばない。
 * 1リクエストで枚数分のAI呼び出しをすると php-fpm / httpd のタイムアウトを超えるため、
 * 読み取りは別リクエスト（ReceiptParseTest）に分けている。
 */
class ReceiptUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * iPhone の HEIC 写真に近いファイルを作る。
     *
     * UploadedFile::fake()->create() は中身が空のファイルを作るため、
     * シグネチャを見るバリデーションを通らない。ISO-BMFF の ftyp ボックスを自分で書く。
     */
    private function fakeHeicFile(string $name = 'IMG_0001.heic'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'heic');

        file_put_contents(
            $path,
            "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heicmif1".str_repeat("\x00", 2048),
        );

        return new UploadedFile($path, $name, 'image/heic', null, true);
    }

    public function test_アップロード画面が表示できる(): void
    {
        $this->get(route('receipts.create'))->assertOk();
    }

    public function test_アップロードしても未登録の件数が案内される(): void
    {
        ReceiptImage::create(['path' => 'receipts/a.jpg', 'status' => 'pending']);
        ReceiptImage::create(['path' => 'receipts/b.jpg', 'status' => 'processed']);

        $response = $this->get(route('receipts.create'));

        $response->assertOk();
        $response->assertViewHas('pendingCount', 2);
    }

    public function test_アップロードすると保存だけして未登録レシート画面へ行く(): void
    {
        $response = $this->post(route('receipts.store'), [
            'images' => [UploadedFile::fake()->image('receipt.jpg')],
        ]);

        $response->assertRedirect(route('receipts.pending', ['autostart' => 1]));

        $receipt = ReceiptImage::firstOrFail();

        // この時点ではまだAIを呼んでいない
        $this->assertSame('pending', $receipt->status);
        $this->assertNull($receipt->parsed_data);
        Storage::disk('public')->assertExists($receipt->path);
    }

    public function test_複数枚まとめてアップロードできる(): void
    {
        $response = $this->post(route('receipts.store'), [
            'images' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.png'),
                UploadedFile::fake()->image('c.gif'),
            ],
        ]);

        $response->assertRedirect(route('receipts.pending', ['autostart' => 1]));
        $this->assertSame(3, ReceiptImage::where('status', 'pending')->count());
    }

    public function test_アップロード後は自動で読み取りが始まる合図が渡る(): void
    {
        // セッションのフラッシュだと遷移直後のリロードで消えてしまうので、
        // 「読み取りを自動で始める」合図はクエリ文字列で渡している
        $response = $this->post(route('receipts.store'), [
            'images' => [UploadedFile::fake()->image('receipt.jpg')],
        ]);

        $response->assertRedirect(route('receipts.pending', ['autostart' => 1]));

        $this->get(route('receipts.pending', ['autostart' => 1]))
            ->assertOk()
            ->assertViewHas('autoStart', true);

        $this->get(route('receipts.pending'))
            ->assertOk()
            ->assertViewHas('autoStart', false);
    }

    public function test_画像以外はアップロードできない(): void
    {
        $response = $this->from(route('receipts.create'))->post(route('receipts.store'), [
            'images' => [UploadedFile::fake()->create('note.txt', 10)],
        ]);

        $response->assertSessionHasErrors('images.0');
        $this->assertSame(0, ReceiptImage::count());
    }

    public function test_拡張子だけ画像でも中身が画像でなければ弾く(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fake');
        file_put_contents($path, "<?php echo 'not an image'; ");

        $response = $this->from(route('receipts.create'))->post(route('receipts.store'), [
            'images' => [new UploadedFile($path, 'evil.jpg', 'image/jpeg', null, true)],
        ]);

        $response->assertSessionHasErrors('images.0');
        $this->assertSame(0, ReceiptImage::count());
    }

    public function test_HEIC画像もアップロードできる(): void
    {
        $response = $this->from(route('receipts.create'))->post(route('receipts.store'), [
            'images' => [$this->fakeHeicFile()],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, ReceiptImage::count());

        // 拡張子が落ちると解析側で MIME タイプを間違えるので、必ず残っていること
        $this->assertMatchesRegularExpression('/\.(heic|jpg)$/', ReceiptImage::firstOrFail()->path);
    }

    public function test_中身に合わせた拡張子で保存する(): void
    {
        // 名前は .jpg だが中身は PNG（端末側のリネームでよくある）
        $file = UploadedFile::fake()->image('photo.png');
        $renamed = new UploadedFile($file->getRealPath(), 'photo.jpg', 'image/jpeg', null, true);

        $this->post(route('receipts.store'), ['images' => [$renamed]]);

        $this->assertStringEndsWith('.png', ReceiptImage::firstOrFail()->path);
    }

    public function test_ファイルを選ばないとエラーになる(): void
    {
        $response = $this->from(route('receipts.create'))->post(route('receipts.store'), []);

        $response->assertSessionHasErrors('images');
        $this->assertSame(0, ReceiptImage::count());
    }

    public function test_上限を超える枚数は弾く(): void
    {
        config(['services.receipt.max_files_per_upload' => 2]);

        $response = $this->from(route('receipts.create'))->post(route('receipts.store'), [
            'images' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
                UploadedFile::fake()->image('c.jpg'),
            ],
        ]);

        $response->assertSessionHasErrors('images');
        $this->assertSame(0, ReceiptImage::count());
    }

    public function test_大きい写真は縮小して向きも直す(): void
    {
        if (! class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick が無い環境では縮小しない');
        }

        // 縦長（EXIF Orientation=6 は「右90度回して見る」）の大きなJPEGを作る
        $image = new \Imagick();
        $image->newImage(3000, 4000, new \ImagickPixel('white'));
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(100);
        $image->setImageOrientation(\Imagick::ORIENTATION_RIGHTTOP);

        $path = tempnam(sys_get_temp_dir(), 'receipt').'.jpg';
        file_put_contents($path, $image->getImageBlob());
        $image->clear();

        $this->post(route('receipts.store'), [
            'images' => [new UploadedFile($path, 'big.jpg', 'image/jpeg', null, true)],
        ]);

        $receipt = ReceiptImage::firstOrFail();
        $stored = new \Imagick(Storage::disk('public')->path($receipt->path));

        // 長辺は2000pxまで
        $this->assertLessThanOrEqual(2000, max($stored->getImageWidth(), $stored->getImageHeight()));

        // EXIF を落とす前にピクセルを回しているので、見た目の向きは変わらない
        // （元は縦長 3000x4000 を右90度 → 横長に見える。回転後は 4000x3000 相当）
        $this->assertGreaterThan($stored->getImageHeight(), $stored->getImageWidth());
        $this->assertSame(\Imagick::ORIENTATION_TOPLEFT, $stored->getImageOrientation());

        $stored->clear();
        @unlink($path);
    }
}
