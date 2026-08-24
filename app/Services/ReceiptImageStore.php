<?php

namespace App\Services;

use App\Rules\SupportedReceiptImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * レシート画像を public ディスクへ保存するサービス。
 *
 * iPhone の標準設定で撮影した写真は HEIC/HEIF になる。
 * Gemini / Claude はどちらも HEIC を直接受け取れないため、
 * 保存時に JPEG へ変換しておく。変換できない環境では元のまま保存し、
 * 解析側が正しい MIME タイプを送れるようにする。
 *
 * あわせて、大きすぎる写真は縮小する。iPhone の写真はそのままだと 5〜10MB あり、
 *   - Claude は base64 で約5MBを超えると受け取れない（大きいレシートが毎回失敗する）
 *   - 20枚溜めるとVMのディスクを200MB食う
 *   - 送信のたびに無駄な通信費と待ち時間がかかる
 * レシートの文字は長辺2000pxあれば十分読める。
 * ついでに EXIF（撮影場所を含む）も落とす。
 */
class ReceiptImageStore
{
    /** 変換対象の拡張子 */
    private const CONVERTIBLE_EXTENSIONS = ['heic', 'heif', 'avif'];

    /** 保存先ディレクトリ */
    private const DIRECTORY = 'receipts';

    /** これより長辺が大きい画像は縮小する（レシートの文字はこれで十分読める） */
    public const MAX_EDGE_PIXELS = 2000;

    /** これより小さいファイルはそのまま（縮小して逆に大きくなることがある） */
    private const MIN_BYTES_TO_SHRINK = 500 * 1024;

    /** 縮小後の JPEG 品質 */
    private const JPEG_QUALITY = 80;

    /**
     * 画像を保存し、public ディスク上の相対パスを返す。
     */
    public function store(UploadedFile $file): string
    {
        // Laravel 既定の hashName() は「中身から推定した MIME」から拡張子を決めるため、
        // HEIC を知らない環境では拡張子なしのファイル名になってしまう。
        // あとで MIME タイプを決めるのに拡張子を使うので、必ず付くようにする。
        $path = $file->storeAs(self::DIRECTORY, Str::random(40).'.'.$this->extensionOf($file), 'public');

        if ($path === false) {
            throw new RuntimeException('レシート画像を保存できませんでした。ディスクの空きを確認してください。');
        }

        return $this->shrinkIfNeeded($this->convertToJpegIfNeeded($path));
    }

    /**
     * 保存に使う拡張子。
     *
     * ファイルの中身から判定した形式を最優先にする。
     * 端末側でリネームされた「中身はPNGなのに名前は .jpg」を
     * そのまま .jpg で保存すると、解析時に image/jpeg と申告して PNG を送ることになり
     * Gemini / Claude 側で弾かれるため。
     */
    private function extensionOf(UploadedFile $file): string
    {
        $detected = SupportedReceiptImage::detectFormat($file->getRealPath());

        if ($detected !== null) {
            return $detected;
        }

        $extension = strtolower($file->getClientOriginalExtension());

        // バリデーションを通らない経路から呼ばれても、想定外の拡張子で保存しない
        return in_array($extension, SupportedReceiptImage::EXTENSIONS, true) ? $extension : 'jpg';
    }

    /**
     * HEIC/HEIF を JPEG に変換する。変換できなければ元のパスをそのまま返す。
     */
    private function convertToJpegIfNeeded(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::CONVERTIBLE_EXTENSIONS, true)) {
            return $path;
        }

        if (! class_exists(\Imagick::class)) {
            // Imagick が無い環境では変換できないので元のまま扱う
            return $path;
        }

        $disk = Storage::disk('public');
        $sourcePath = $disk->path($path);
        $jpegPath = self::DIRECTORY.'/'.Str::beforeLast(basename($path), '.').'.jpg';

        try {
            $image = new \Imagick($sourcePath);
            $image->setIteratorIndex(0);
            // EXIF を落とすと向きの情報も消えるので、先にピクセルを回しておく
            $this->autoOrient($image);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(90);
            // 画像以外のメタデータ(位置情報など)は落とす
            $image->stripImage();

            $disk->put($jpegPath, $image->getImageBlob());

            $image->clear();
            $image->destroy();
        } catch (Throwable $e) {
            Log::warning('HEIC画像のJPEG変換に失敗しました。元の形式のまま扱います。', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return $path;
        }

        $disk->delete($path);

        return $jpegPath;
    }

    /**
     * EXIF の向きに合わせて実際にピクセルを回す。
     *
     * ImageMagick は読み込み時に回転しない。向きは EXIF にしか無いので、
     * stripImage() で EXIF を落とすと**縦に撮ったレシートが横倒しで保存される**。
     * 画面のサムネイルが寝るだけでなく、その画像をそのままAIに送るので読み取り精度も落ちる。
     */
    private function autoOrient(\Imagick $image): void
    {
        switch ($image->getImageOrientation()) {
            case \Imagick::ORIENTATION_BOTTOMRIGHT:
                $image->rotateImage('#000', 180);
                break;
            case \Imagick::ORIENTATION_RIGHTTOP:
                $image->rotateImage('#000', 90);
                break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:
                $image->rotateImage('#000', -90);
                break;
        }

        $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }

    /**
     * 上書き保存する。書き込みに失敗したら**元のファイルには触らない**。
     *
     * put() は中身を切り詰めてから書くので、ディスクが一杯だと
     * 「唯一のレシート画像が0バイトになる」ことが起きる。
     * いったん別名で書ききってから差し替える。
     */
    private function replaceContents(string $path, string $blob): bool
    {
        if ($blob === '') {
            return false;
        }

        $disk = Storage::disk('public');
        $temporary = $path.'.tmp';

        try {
            if (! $disk->put($temporary, $blob) || $disk->size($temporary) !== strlen($blob)) {
                $disk->delete($temporary);

                return false;
            }

            $disk->delete($path);

            return $disk->move($temporary, $path);
        } catch (Throwable $e) {
            $disk->delete($temporary);

            Log::warning('レシート画像の書き戻しに失敗しました。元の画像のまま扱います。', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 大きすぎる画像を縮小して保存し直す。できなければ元のパスをそのまま返す。
     *
     * 失敗しても解析は続けたいので、例外にはしない（元の画像で送るだけ）。
     */
    private function shrinkIfNeeded(string $path): string
    {
        if (! class_exists(\Imagick::class)) {
            return $path;
        }

        $disk = Storage::disk('public');

        try {
            if ($disk->size($path) < self::MIN_BYTES_TO_SHRINK) {
                return $path;
            }

            $sourcePath = $disk->path($path);
            $image = new \Imagick($sourcePath);
            // 複数フレーム（Live Photo の HEIC など）は先頭だけを扱う
            $image->setIteratorIndex(0);

            // EXIF を落とす前にピクセルを回す
            $this->autoOrient($image);

            if ($image->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
                $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $longEdge = max($width, $height);

            if ($longEdge > self::MAX_EDGE_PIXELS) {
                $scale = self::MAX_EDGE_PIXELS / $longEdge;
                $image->resizeImage(
                    (int) round($width * $scale),
                    (int) round($height * $scale),
                    \Imagick::FILTER_LANCZOS,
                    1,
                );
                $image->setImageCompressionQuality(self::JPEG_QUALITY);
            }

            // 縮小が不要でも、位置情報（EXIF）だけは落としておく
            $image->stripImage();

            $blob = $image->getImageBlob();

            $image->clear();
            $image->destroy();

            $this->replaceContents($path, $blob);
        } catch (Throwable $e) {
            Log::warning('レシート画像の縮小に失敗しました。元のサイズのまま扱います。', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);
        }

        return $path;
    }
}
