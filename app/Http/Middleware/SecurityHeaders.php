<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * すべてのHTMLレスポンスにセキュリティ用のヘッダーを付ける。
 *
 * 家庭内LANの単一ユーザーなので、いま実際に成立する攻撃があるわけではない。
 * 入れておく理由は「唯一の防波堤が設定の既定値ひとつ」という状態をやめるため。
 *  - クリックジャッキングを防いでいるものが1つも無い（本命のCSRFトークンは
 *    「別サイトのフォームからPOST」は止めるが、「iframeに埋めて本人に押させる」は止めない）。
 *    一括編集・予算の上書き・除外ルールの削除・AI課金は、どれも一度踏めば戻せない。
 *  - `nosniff` が無いと、PHPが返すもの（CSVエクスポート等）のMIMEを推測されうる。
 *    ※ `/storage/` のレシート画像は Web サーバーが直接返すのでここは通らない。
 *      あちらは httpd 側で同じヘッダーを付けること（README「セキュリティと公開範囲」）。
 *  - HSTS は、この家計簿をLANの外に出す日に必要になる。
 *
 * CSP は既定では **Report-Only**（違反をブラウザのコンソールに出すだけ）。
 * 画面が壊れないことを目で確かめてから `CSP_ENFORCE=true` で強制に切り替える。
 */
class SecurityHeaders
{
    /**
     * CSP で読み込みを許可する外部オリジン。
     *
     * ここに無いオリジンのスクリプトは（強制モードでは）読み込まれない。
     * CDNを増やすときは必ずここも足すこと。
     *
     * @var list<string>
     */
    public const SCRIPT_ORIGINS = [
        'https://cdn.tailwindcss.com',
        'https://cdn.jsdelivr.net',
    ];

    /** CSP違反の送り先（Report-Only を「見えるように」するために必須） */
    public const REPORT_PATH = '/csp-report';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // frame-ancestors と重複するが、CSPを Report-Only で運用する間の実効的な防波堤
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        // COOP は https のときだけ効く。いまは効かないが、httpsにした日に効きはじめる
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        // いまは getUserMedia を使っていない。将来使うときのための先回り
        $response->headers->set(
            'Permissions-Policy',
            'camera=(self), microphone=(), geolocation=(), payment=(), usb=()',
        );

        // CSP は HTML にだけ付ける。JSON（レシート読み取りは1枚1リクエスト）や
        // CSVのダウンロードに数百バイトのヘッダーを乗せても意味が無い。
        if ($this->isHtml($response)) {
            // Reporting API（report-to）は https のときしか使えない。
            // ブラウザは非セキュアな送り先を黙って捨てるうえ、report-to があると
            // 古い report-uri のほうを無視する。http のまま両方書くと
            // 「どこにも届かない Report-Only」になり、確認の運用が成り立たない。
            $useReportTo = $request->secure();

            if ($useReportTo) {
                $response->headers->set(
                    'Reporting-Endpoints',
                    'csp-endpoint="'.$request->getSchemeAndHttpHost().self::REPORT_PATH.'"',
                );
            }

            $header = config('security.csp_enforce', false)
                ? 'Content-Security-Policy'
                : 'Content-Security-Policy-Report-Only';

            $response->headers->set($header, self::contentSecurityPolicy($useReportTo));
        }

        // HSTS は https で応答したときだけ意味がある。
        // http のまま送っても無視されるが、誤解を招くので出さない。
        if ($request->secure() && config('security.hsts', false)) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.(int) config('security.hsts_max_age').'; includeSubDomains',
            );
        }

        return $response;
    }

    /**
     * CSP の中身。
     *
     * `script-src` に 'unsafe-inline' が入っているのは、画面ごとの動作を
     * Blade 内の <script> で書いているため。nonce に移すには全テンプレートを
     * 触る必要があるので別タスクにしてある。
     * それでも「許可したオリジン以外の外部スクリプトを読み込めない」効果は残るので、
     * CDN が乗っ取られる以外の経路（差し込まれた <script src>）は塞げる。
     */
    public static function contentSecurityPolicy(bool $withReportTo = false): string
    {
        $origins = implode(' ', self::SCRIPT_ORIGINS);

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self' 'unsafe-inline' ".$origins,
            // Tailwind の CDN 版は <style> を差し込むので 'unsafe-inline' が要る
            "style-src 'self' 'unsafe-inline'",
            // レシート画像（/storage）とグラフの canvas
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "manifest-src 'self'",
            "worker-src 'self'",
            // 送り先の無い Report-Only は、Chrome が「効果が無い」と言って捨てる。
            // 送り先を書いて初めて「壊れる箇所」を先に知ることができる。
            'report-uri '.self::REPORT_PATH,
        ];

        if ($withReportTo) {
            $directives[] = 'report-to csp-endpoint';
        }

        return implode('; ', $directives);
    }

    private function isHtml(Response $response): bool
    {
        // 204/304 は prepare() で Content-Type が消される。そこに数百バイトの
        // ポリシーを載せても意味が無いので、HTML と明示されたものだけに付ける。
        return str_contains((string) $response->headers->get('Content-Type', ''), 'text/html');
    }
}
