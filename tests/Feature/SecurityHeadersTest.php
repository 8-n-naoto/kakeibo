<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * セキュリティ用のレスポンスヘッダー。
 *
 * いま実際に成立する攻撃があるわけではないが、クリックジャッキングを
 * `session.same_site` の既定値ひとつで防いでいる状態をやめるために入れている。
 * 「消えていないこと」を見張るのがこのテストの役目。
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_設定ファイルが読み込まれている(): void
    {
        // config:cache が古いままだと config('security.*') が null になり、
        // CSP も HSTS も「設定したのに効かない」状態が黙って続く
        $this->assertNotNull(config('security.hsts_max_age'), 'config/security.php が読み込まれていません（php artisan config:clear）');
        $this->assertIsBool(config('security.csp_enforce'));
        $this->assertIsBool(config('security.hsts'));
    }

    public function test_ログイン画面にもヘッダーが付く(): void
    {
        Auth::logout();

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }

    public function test_ログイン後の画面にもヘッダーが付く(): void
    {
        $response = $this->get(route('transactions.index'));

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_見つからない画面にもヘッダーが付く(): void
    {
        // web グループに append するとCSRFより内側になり、404 や 419 が素通りする。
        // グローバルの一番外に置いてあることの確認。
        $response = $this->get('/no-such-page');

        $response->assertNotFound();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_CSPは既定ではReportOnlyで送る(): void
    {
        // 画面が壊れないことを目で確かめるまでは強制しない
        config(['security.csp_enforce' => false]);

        $response = $this->get(route('transactions.index'));

        $response->assertHeaderMissing('Content-Security-Policy');
        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_設定を入れるとCSPを強制する(): void
    {
        config(['security.csp_enforce' => true]);

        $response = $this->get(route('transactions.index'));

        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_ReportOnlyには必ず送り先を書く(): void
    {
        // 送り先の無い Report-Only は「効果が無い」とブラウザに捨てられる。
        // つまり「違反が出ないのを確かめてから強制にする」運用が成り立たなくなる。
        $response = $this->get('http://localhost/transactions');

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString('report-uri '.SecurityHeaders::REPORT_PATH, $policy);
    }

    public function test_httpではreport_toを書かない(): void
    {
        // Reporting API の送り先は https でないとブラウザに捨てられる。
        // さらに report-to があると report-uri のほうが無視されるので、
        // http のまま両方書くと「どこにも届かない Report-Only」になる。
        $response = $this->get('http://localhost/transactions');

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringNotContainsString('report-to', $policy);
        $response->assertHeaderMissing('Reporting-Endpoints');
    }

    public function test_httpsではreport_toも書く(): void
    {
        $response = $this->get('https://localhost/transactions');

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString('report-to csp-endpoint', $policy);
        $response->assertHeader(
            'Reporting-Endpoints',
            'csp-endpoint="https://localhost'.SecurityHeaders::REPORT_PATH.'"',
        );
    }

    public function test_CSP違反レポートを受け取れる(): void
    {
        Auth::logout();
        Log::spy();

        // ブラウザはCSRFトークンを付けないし、ログイン画面での違反も拾いたい
        $response = $this->postJson(SecurityHeaders::REPORT_PATH, [
            'csp-report' => ['violated-directive' => 'script-src', 'blocked-uri' => 'https://example.test/x.js'],
        ]);

        $response->assertNoContent();
        Log::shouldHaveReceived('warning');
    }

    public function test_ブラウザが送るContentTypeでも受け取れる(): void
    {
        // 実際のブラウザは application/json ではなく application/csp-report を送る
        Auth::logout();
        Log::spy();

        $response = $this->call(
            'POST',
            SecurityHeaders::REPORT_PATH,
            [], [], [],
            ['CONTENT_TYPE' => 'application/csp-report'],
            '{"csp-report":{"violated-directive":"script-src","blocked-uri":"https://example.test/x.js"}}',
        );

        $this->assertSame(204, $response->getStatusCode());
        Log::shouldHaveReceived('warning');
    }

    public function test_大きすぎるレポートはログに残さない(): void
    {
        // 未認証で叩ける口なので、post_max_size いっぱいの本文をパースさせない
        Auth::logout();
        Log::spy();

        $response = $this->call(
            'POST',
            SecurityHeaders::REPORT_PATH,
            [], [], [],
            ['CONTENT_TYPE' => 'application/csp-report'],
            str_repeat('x', 20000),
        );

        $this->assertSame(204, $response->getStatusCode());
        Log::shouldNotHaveReceived('warning');
    }

    public function test_CSPはJSONやダウンロードには付けない(): void
    {
        // レシートの読み取りは1枚1リクエストで何十回も飛ぶ。
        // 数百バイトのヘッダーを毎回乗せる意味は無い。
        $response = $this->get(route('transactions.export'));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_CSPが外部スクリプトのオリジンを絞っている(): void
    {
        $policy = SecurityHeaders::contentSecurityPolicy();

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);

        foreach (SecurityHeaders::SCRIPT_ORIGINS as $origin) {
            $this->assertStringContainsString($origin, $policy);
        }
    }

    public function test_画面が読み込むCDNはすべてCSPで許可されている(): void
    {
        // テンプレートにCDNを足したのにCSPへ足し忘れる、を防ぐ。
        // 2ファイルだけ見ても意味が無いので、ビュー全部を走査する。
        $policy = SecurityHeaders::contentSecurityPolicy();
        $found = 0;

        foreach ($this->externalScriptUrls() as $file => $urls) {
            foreach ($urls as $url) {
                $found++;
                $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST);

                $this->assertStringContainsString(
                    $origin,
                    $policy,
                    $origin.' が CSP に入っていません（'.$file.'）',
                );
            }
        }

        $this->assertGreaterThan(0, $found, '外部スクリプトが1つも見つかりません（走査が壊れています）');
    }

    public function test_外部スクリプトはバージョンを固定する(): void
    {
        // 浮動指定だと、配布元が乗っ取られた日に開いた瞬間このオリジンで他人のJSが動く
        foreach ($this->externalScriptUrls() as $file => $urls) {
            foreach ($urls as $url) {
                $this->assertMatchesRegularExpression(
                    '/\d+\.\d+\.\d+/',
                    $url,
                    $url.' にバージョンが入っていません（'.$file.'）',
                );
            }
        }
    }

    public function test_httpではHSTSを送らない(): void
    {
        config(['security.hsts' => true]);

        $response = $this->get('http://localhost/transactions');

        // http のまま送っても無視されるだけだが、送れているように見えるのが困る
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_httpsで設定を入れたときだけHSTSを送る(): void
    {
        config(['security.hsts' => true, 'security.hsts_max_age' => 3600]);

        $response = $this->get('https://localhost/transactions');

        $this->assertSame('max-age=3600; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_httpsでも設定がなければHSTSを送らない(): void
    {
        config(['security.hsts' => false]);

        $response = $this->get('https://localhost/transactions');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    /**
     * すべてのビュー（と public/*.html）が読み込む外部スクリプトのURL。
     *
     * @return array<string, list<string>>
     */
    private function externalScriptUrls(): array
    {
        $files = array_merge(
            $this->filesIn(resource_path('views'), 'blade.php'),
            glob(public_path('*.html')) ?: [],
        );

        $result = [];

        foreach ($files as $file) {
            // <script src> だけでなく <link href>（外部スタイル・フォント）も見る。
            // style-src / font-src には外部オリジンを1つも許していないので、
            // 足したらCSPも直さないと CSP_ENFORCE=true で真っ白になる。
            preg_match_all(
                '#<(?:script[^>]+src|link[^>]+href)=["\'](https?://[^"\']+)["\']#',
                (string) file_get_contents($file),
                $matches,
            );

            if ($matches[1] !== []) {
                // 同名のビューが8つある（index.blade.php）。basename だと上書きされて消える
                $key = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                $result[$key] = array_values(array_unique($matches[1]));
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $directory, string $suffix): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        $found = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
