<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * 単一ユーザー前提のログイン。
 *
 * 家計データは LAN 内でも他人に見えてよいものではないので、
 * ログイン画面以外はすべて auth ミドルウェアの内側に置く。
 */
class LoginController extends Controller
{
    /** 連続失敗を許す回数 */
    private const MAX_ATTEMPTS = 5;

    /** ロックする秒数 */
    private const LOCK_SECONDS = 60;

    public function create()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            // 単一ユーザーのアプリなので、ログに失敗が並んでいたら即座に異常と分かる。
            // 記録が無いと、総当たりが行われたこと自体に気づけない。
            Log::warning('ログイン試行が制限に達しました', [
                'email' => $this->maskedEmail($credentials['email']),
                'ip' => $request->ip(),
                'user_agent' => mb_strimwidth((string) $request->userAgent(), 0, 200, ''),
            ]);

            throw ValidationException::withMessages([
                'email' => '試行回数が多すぎます。'.RateLimiter::availableIn($key).'秒後にもう一度お試しください。',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, self::LOCK_SECONDS);

            Log::warning('ログインに失敗しました', [
                'email' => $this->maskedEmail($credentials['email']),
                'ip' => $request->ip(),
                'user_agent' => mb_strimwidth((string) $request->userAgent(), 0, 200, ''),
                'attempts' => RateLimiter::attempts($key),
            ]);

            throw ValidationException::withMessages([
                'email' => 'メールアドレスまたはパスワードが違います。',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'ログアウトしました。');
    }

    private function throttleKey(Request $request): string
    {
        return 'login:'.mb_strtolower((string) $request->input('email')).'|'.$request->ip();
    }

    /**
     * ログに残すメールアドレス。
     *
     * 攻撃者が入れた文字列がそのまま入るので、丸ごとは残さない
     * （ログを読むのは本人だけだが、長大な文字列や制御文字でログを汚されない）。
     */
    private function maskedEmail(string $email): string
    {
        $email = mb_substr(trim($email), 0, 80);
        $at = mb_strrpos($email, '@');

        // ローカル部が短いと、隠したつもりで丸ごと出てしまう
        if ($at === false || $at < 3) {
            return mb_substr($email, 0, 1).'***';
        }

        return mb_substr($email, 0, 2).'***'.mb_substr($email, $at);
    }
}
