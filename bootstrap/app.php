<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 未ログインでアプリ内を開いたらログイン画面へ、ログイン済みで /login を開いたらダッシュボードへ
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');

        // すべての応答にセキュリティ用のヘッダーを付ける（CSPは既定で Report-Only）。
        // web グループに append すると CSRF より内側になり、404 や 419 の画面が素通りする。
        // 一番外側に置いて、エラー画面も必ず通るようにする。
        $middleware->prepend(SecurityHeaders::class);

        // ブラウザが送ってくるCSP違反レポートにはCSRFトークンが無い
        $middleware->validateCsrfTokens(except: [ltrim(SecurityHeaders::REPORT_PATH, '/')]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // アップロードの合計サイズが php.ini の post_max_size を超えると、
        // PHP は $_POST も $_FILES も捨てる。その結果 CSRFトークンが消えて
        // 「419 Page Expired」の白い画面になり、原因がまったく分からない。
        // 実際の原因を伝えるメッセージに差し替える。
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            $contentLength = (int) $request->server('CONTENT_LENGTH', 0);

            if ($request->isMethod('POST') && $contentLength > 0 && $request->post() === []) {
                $limit = ini_get('post_max_size') ?: '不明';

                return back()->withErrors([
                    'images' => 'アップロードしたファイルの合計サイズが大きすぎます'
                        .'（サーバーの上限は '.$limit.'）。枚数を減らしてお試しください。',
                ]);
            }

            // それ以外は Laravel 既定の 419 のまま
            return null;
        });
    })->create();
