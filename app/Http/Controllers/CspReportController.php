<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ブラウザから送られてくる CSP 違反レポートの受け口。
 *
 * Report-Only の CSP は送り先が無いとブラウザに無視される（Chrome は
 * 「report-uri が無いのでこのポリシーは効果がありません」と言って捨てる）。
 * つまりここが無いと、「違反が出ないことを確かめてから強制にする」という
 * 運用そのものが成り立たない。
 *
 * ログインの内側には置かない。ログイン画面自体の違反も拾いたいため。
 * 代わりに件数と1件あたりの長さを絞って、ログを溢れさせないようにする。
 */
class CspReportController extends Controller
{
    /** 1レポートあたりログに残す最大文字数 */
    private const MAX_LENGTH = 2000;

    /** これより大きい本文はレポートとして扱わない（受け口は未認証なので、先に切る） */
    private const MAX_BODY_BYTES = 16384;

    public function __invoke(Request $request): Response
    {
        $raw = (string) $request->getContent();

        // json_decode → json_encode の前に切る。未認証で叩ける口なので、
        // post_max_size いっぱいの本文をパースさせない。
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return response()->noContent();
        }

        // レポートの Content-Type はブラウザによって違う（application/csp-report /
        // application/reports+json）。形式も違う（report-uri は {"csp-report": {...}}、
        // report-to は配列）。中身を解釈せず、そのまま短くして残す。
        $payload = json_decode($raw, true);

        $body = is_array($payload) && $payload !== []
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $raw;

        Log::warning('CSP違反レポート', [
            'report' => Str::limit((string) $body, self::MAX_LENGTH),
            'enforced' => (bool) config('security.csp_enforce', false),
        ]);

        // ブラウザはこの応答を見ない。本文を返す必要も無い。
        return response()->noContent();
    }

    /** ルート定義とミドルウェアの除外で同じパスを使うための入口 */
    public static function path(): string
    {
        return SecurityHeaders::REPORT_PATH;
    }
}
