<?php

namespace Tests\Unit;

use App\Rules\SupportedReceiptImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * アップロードされたファイルの形式判定。
 *
 * Laravel の `mimes` ルールは libmagic 依存で HEIC を弾いてしまうので、
 * 先頭のシグネチャで自前判定している。ここが緩むと
 * 画像でないファイルが public 配下に置かれる。
 */
class SupportedReceiptImageTest extends TestCase
{
    /** @var array<int, string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function fileWith(string $head): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($path, $head.str_repeat("\x00", 64));
        $this->paths[] = $path;

        return $path;
    }

    public static function formatProvider(): array
    {
        return [
            'JPEG' => ["\xFF\xD8\xFF\xE0", 'jpg'],
            'PNG' => ["\x89PNG\r\n\x1A\n", 'png'],
            'GIF87a' => ['GIF87a', 'gif'],
            'GIF89a' => ['GIF89a', 'gif'],
            'WebP' => ["RIFF\x00\x00\x00\x00WEBP", 'webp'],
            'HEIC' => ["\x00\x00\x00\x18ftypheic", 'heic'],
            'HEIF(mif1)' => ["\x00\x00\x00\x20ftypmif1", 'heic'],
            'AVIF' => ["\x00\x00\x00\x1cftypavif", 'avif'],
        ];
    }

    #[DataProvider('formatProvider')]
    public function test_対応形式を判定できる(string $head, string $expected): void
    {
        $this->assertSame($expected, SupportedReceiptImage::detectFormat($this->fileWith($head)));
    }

    public static function rejectedProvider(): array
    {
        return [
            'PHPソース' => ["<?php echo 'x';"],
            'ZIP' => ["PK\x03\x04"],
            'PDF' => ['%PDF-1.7'],
            'MP4(画像ではないftyp)' => ["\x00\x00\x00\x18ftypisom"],
            'テキスト' => ['これはテキストです'],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function test_画像でないファイルは判定できない(string $head): void
    {
        $this->assertNull(SupportedReceiptImage::detectFormat($this->fileWith($head)));
    }

    public function test_短すぎるファイルは判定できない(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($path, "\xFF\xD8");
        $this->paths[] = $path;

        $this->assertNull(SupportedReceiptImage::detectFormat($path));
    }

    public function test_存在しないパスは判定できない(): void
    {
        $this->assertNull(SupportedReceiptImage::detectFormat('/no/such/file'));
        $this->assertNull(SupportedReceiptImage::detectFormat(false));
    }

    public function test_判定結果はすべて許可拡張子に含まれる(): void
    {
        foreach (self::formatProvider() as [$head, $expected]) {
            $this->assertContains($expected, SupportedReceiptImage::EXTENSIONS);
        }
    }
}
