<?php

namespace App\Providers;

use App\Models\Transaction;
use App\Observers\TransactionObserver;
use App\Services\ClaudeReceiptParser;
use App\Services\GeminiReceiptParser;
use App\Services\MerchantCategoryGuesser;
use App\Services\ReceiptParser;
use App\Support\CategoryIdIndex;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // レシート解析に使うAIを .env の RECEIPT_AI_DRIVER で切り替える
        $this->app->bind(ReceiptParser::class, function ($app) {
            $driver = config('services.receipt_ai.driver', 'gemini');

            return match ($driver) {
                'gemini' => $app->make(GeminiReceiptParser::class),
                'claude', 'anthropic' => $app->make(ClaudeReceiptParser::class),
                default => throw new InvalidArgumentException(
                    "RECEIPT_AI_DRIVER の値が不正です: {$driver}（gemini または claude）"
                ),
            };
        });

        // 店名→カテゴリの学習ルールは1リクエスト内で使い回す。
        // scoped にしておくと、キュー/Octane のように使い回されるプロセスでも
        // リクエストごとに作り直される（singleton だと古い一覧を持ち続ける）。
        $this->app->scoped(MerchantCategoryGuesser::class);

        // カテゴリIDの一覧も1リクエスト内で使い回す
        // （CSV取込の確定は2,000行ぶんのカテゴリを検証するので、行ごとに引かない）
        $this->app->scoped(CategoryIdIndex::class);

        // Observer はイベントごとに container->make() される。
        // ここで束ねないと、内部のカテゴリ一覧のメモが効かず、
        // **取引を1件保存するたびにカテゴリ全件のSELECT**が飛ぶ。
        $this->app->scoped(TransactionObserver::class);
    }

    /**
     * ログインの回数制限。
     *
     * コントローラ側にも「メール＋IPで5回/分」があるが、あれは**IPが変わると別カウント**になる。
     * 家庭内LANの攻撃者はIPを名乗り直せるので、/24 を順に使うだけで 254 × 5 = 毎分1,270回
     * 試せてしまう。狙われるメールアドレスは1つに固定されているので、これは効率がよい。
     *
     * そこで「そのメールアドレスに対する試行」をIPに依らずに数える上限を置く。
     * 1分に10回は、人が打ち間違える分には十分で、総当たりには全く足りない。
     */
    private function configureLoginRateLimiter(): void
    {
        RateLimiter::for('login', function (Request $request) {
            // 配列で送られてくることがある（email[]=x）。(string) にすると
            // 警告→ErrorException で、バリデーションに届く前に500になる
            $rawEmail = $request->input('email');
            $email = is_string($rawEmail) ? mb_strtolower(trim($rawEmail)) : '';

            return [
                // IPを変えても効く（狙われるアカウントは1つなので、ここが本命）
                Limit::perMinute(10)->by('login-email:'.sha1($email)),
                // 同じIPからの総当たり・メールアドレスの総当たり
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 取引が保存されるたびに「店名 → カテゴリ」を覚える
        Transaction::observe(TransactionObserver::class);

        $this->configureLoginRateLimiter();
    }
}
