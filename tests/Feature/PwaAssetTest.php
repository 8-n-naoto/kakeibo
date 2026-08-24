<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * PWA として成立しているか（マニフェスト・アイコン・Service Worker が揃っているか）。
 *
 * これらは public/ 配下の静的ファイルなのでHTTPでは取りに行かず、
 * 「ファイルが存在して中身が妥当か」と「HTMLから参照されているか」を見る。
 */
class PwaAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_マニフェストが妥当なJSONである(): void
    {
        $path = public_path('manifest.webmanifest');

        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($manifest, 'manifest.webmanifest が JSON として読めません');
        $this->assertSame('家計簿アプリ', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/dashboard', $manifest['start_url']);
        $this->assertNotEmpty($manifest['icons']);
    }

    public function test_マニフェストが指すアイコンが実在する(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')), $icon['src'].' が見つかりません');
        }

        // マスカブルアイコンが1つ以上あること（Android のホーム画面で丸く切られても崩れない）
        $purposes = array_column($manifest['icons'], 'purpose');
        $this->assertContains('maskable', $purposes);
    }

    public function test_ショートカットのリンク先が実在するルートである(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

        foreach ($manifest['shortcuts'] as $shortcut) {
            $matched = true;

            try {
                app('router')->getRoutes()->match(Request::create($shortcut['url'], 'GET'));
            } catch (NotFoundHttpException) {
                $matched = false;
            }

            $this->assertTrue($matched, $shortcut['url'].' に対応するルートがありません');
        }
    }

    public function test_ServiceWorkerがHTMLをキャッシュしない設計になっている(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString('offline.html', $sw);
        $this->assertStringContainsString("request.mode === 'navigate'", $sw);
        // 画面遷移はネットワーク優先（fetch を先に呼ぶ）であること
        $this->assertMatchesRegularExpression('/fetch\(request\)\s*\.catch/', $sw);
    }

    public function test_ServiceWorkerがprecacheするファイルがすべて実在する(): void
    {
        // cache.addAll は1つでも404だと全体が reject され、Service Worker の
        // インストールごと失敗する。マニフェストに載っていないアイコンも含めて確認する。
        $sw = (string) file_get_contents(public_path('sw.js'));

        preg_match('/const PRECACHE_URLS = \[(.*?)\];/s', $sw, $matches);
        $this->assertNotEmpty($matches, 'sw.js に PRECACHE_URLS が見つかりません');

        preg_match_all("/'([^']+)'/", $matches[1], $urls);
        $paths = array_filter($urls[1], fn (string $url) => str_starts_with($url, '/'));

        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $this->assertFileExists(public_path(ltrim($path, '/')), $path.' が見つかりません');
        }
    }

    public function test_オフラインページが存在する(): void
    {
        $this->assertFileExists(public_path('offline.html'));
        $this->assertStringContainsString('オフライン', (string) file_get_contents(public_path('offline.html')));
    }

    public function test_画面にマニフェストとアイコンが読み込まれている(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('manifest.webmanifest', false);
        $response->assertSee('apple-touch-icon.png', false);
        $response->assertSee('theme-color', false);
        $response->assertSee('sw.js', false);
    }

    public function test_ログイン画面にもマニフェストが読み込まれている(): void
    {
        // TestCase が既定でログイン済みにするので、ここだけ明示的にログアウトする
        Auth::logout();

        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('manifest.webmanifest', false);
    }
}
