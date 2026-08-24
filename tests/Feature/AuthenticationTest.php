<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * ログイン・ログアウトと、未ログイン時のアクセス制御。
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** このテストクラスは認証そのものを検証するので自動ログインしない */
    protected bool $authenticateByDefault = false;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login:you@example.com|127.0.0.1');
    }

    private function user(string $password = 'password'): User
    {
        return User::factory()->create([
            'email' => 'you@example.com',
            'password' => $password,
        ]);
    }

    public function test_ログイン画面は未ログインでも開ける(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('ログイン');
    }

    public function test_未ログインだとダッシュボードはログイン画面へ飛ばされる(): void
    {
        $this->get(route('dashboard'))->assertRedirect('/login');
    }

    public function test_未ログインだと取引一覧も見られない(): void
    {
        $this->get(route('transactions.index'))->assertRedirect('/login');
    }

    public function test_未ログインだと取引を作成できない(): void
    {
        $response = $this->post(route('transactions.store'), [
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'amount' => 1000,
        ]);

        $response->assertRedirect('/login');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_正しいパスワードでログインできる(): void
    {
        $user = $this->user();

        $response = $this->post(route('login.store'), [
            'email' => 'you@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_パスワードが違うとログインできない(): void
    {
        $this->user();

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'you@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_メールアドレスの形式が不正だとエラーになる(): void
    {
        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'not-an-email',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_連続で失敗するとロックされる(): void
    {
        $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->from(route('login'))->post(route('login.store'), [
                'email' => 'you@example.com',
                'password' => 'wrong-password',
            ]);
        }

        // 6回目は正しいパスワードでも弾かれる
        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'you@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_同一IPからの試行回数に上限がある(): void
    {
        // メールアドレスを変えながら叩く（アカウントの総当たり／ログの汚し）
        $this->user();

        for ($i = 0; $i < 20; $i++) {
            $this->post(route('login.store'), [
                'email' => 'other'.$i.'@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post(route('login.store'), [
            'email' => 'other999@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }

    public function test_IPを変えても同じアカウントへの試行は止まる(): void
    {
        // コントローラ側の制限キーには送信元IPが入っているので、IPを名乗り直せば
        // 「メール＋IPで5回」は素通りできる。家庭内LANでIPは自由に名乗れるので、
        // /24 を順に使うと毎分1,270回試せてしまう。
        // 狙われるアカウントは1つに固定されているため、IPに依らない上限が要る。
        $this->user();

        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.'.$i])
                ->post(route('login.store'), [
                    'email' => 'you@example.com',
                    'password' => 'wrong-password',
                ]);
        }

        // 11回目は、まだ使っていないIPから正しいパスワードで来ても弾く
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.200'])
            ->post(route('login.store'), [
                'email' => 'you@example.com',
                'password' => 'password',
            ]);

        $response->assertStatus(429);
        $this->assertGuest();
    }

    public function test_ログインの失敗はログに残る(): void
    {
        // 単一ユーザーなので、ログに失敗が並んでいたら即座に異常と分かる。
        // 記録が無いと、総当たりが行われたこと自体に気づけない
        $this->user();
        Log::spy();

        $this->from(route('login'))->post(route('login.store'), [
            'email' => 'you@example.com',
            'password' => 'wrong-password',
        ]);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            return str_contains($message, 'ログイン')
                && isset($context['ip'])
                // メールアドレスは丸ごと残さない
                && str_contains((string) $context['email'], '***');
        });
    }

    public function test_ログイン後はログイン画面に行くとダッシュボードへ戻される(): void
    {
        $this->actingAs($this->user());

        $this->get(route('login'))->assertRedirect('/dashboard');
    }

    public function test_ログアウトできる(): void
    {
        $this->actingAs($this->user());

        $response = $this->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_ログインすると元々開こうとした画面へ戻る(): void
    {
        $this->user();

        // 未ログインで取引一覧を開こうとする → intended に記録される
        $this->get(route('transactions.index'))->assertRedirect('/login');

        $response = $this->post(route('login.store'), [
            'email' => 'you@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('transactions.index'));
    }
}
