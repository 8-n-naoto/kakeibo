<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ReceiptImage;
use App\Models\Transaction;
use App\Services\ReceiptParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * レシート1枚の読み取り（アップロードとは別リクエスト）。
 *
 * 1リクエスト＝1枚に固定しているので、何枚アップロードしてもタイムアウトしない。
 * 画面のJavaScriptがこのエンドポイントを順番に呼ぶ。
 */
class ReceiptParseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function parsedResult(array $overrides = []): array
    {
        return array_merge([
            'transaction_date' => '2026-08-01',
            'shop_name' => 'スーパーライフ',
            'total_amount' => 3200,
            'type' => 'expense',
            'suggested_category' => '食費',
            'items' => [['name' => '牛乳', 'amount' => 200]],
            'memo' => null,
            'raw_response' => ['id' => 'interaction_test'],
        ], $overrides);
    }

    private function uploadedReceipt(): ReceiptImage
    {
        return ReceiptImage::create([
            'path' => UploadedFile::fake()->image('receipt.jpg')->store('receipts', 'public'),
            'status' => 'pending',
        ]);
    }

    private function mockParser(array $result): void
    {
        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) use ($result) {
            $mock->shouldReceive('parse')->once()->andReturn($result);
        }));
    }

    public function test_読み取りに成功すると解析結果が保存される(): void
    {
        Category::factory()->create(['name' => '食費']);
        $receipt = $this->uploadedReceipt();
        $this->mockParser($this->parsedResult());

        $response = $this->post(route('receipts.parse', $receipt));

        $response->assertRedirect(route('receipts.pending'));

        $receipt->refresh();

        $this->assertSame('processed', $receipt->status);
        $this->assertSame('スーパーライフ', $receipt->parsed_data['shop_name']);
        $this->assertSame(3200, $receipt->parsed_data['total_amount']);
    }

    public function test_JavaScriptから呼ばれたときはJSONで返す(): void
    {
        $receipt = $this->uploadedReceipt();
        $this->mockParser($this->parsedResult());

        $response = $this->postJson(route('receipts.parse', $receipt));

        $response->assertOk();
        $response->assertJson(['ok' => true, 'id' => $receipt->id, 'status' => 'processed']);
    }

    public function test_読み取りに失敗すると失敗として記録される(): void
    {
        $receipt = $this->uploadedReceipt();

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) {
            $mock->shouldReceive('parse')->andThrow(new RuntimeException('APIエラー'));
        }));

        $response = $this->postJson(route('receipts.parse', $receipt));

        $response->assertStatus(422);
        $response->assertJson(['ok' => false]);

        $receipt->refresh();

        $this->assertSame('failed', $receipt->status);
        $this->assertStringContainsString('APIエラー', (string) $receipt->error_message);
    }

    public function test_フォーム送信で失敗したときはエラー付きで戻る(): void
    {
        $receipt = $this->uploadedReceipt();

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) {
            $mock->shouldReceive('parse')->andThrow(new RuntimeException('APIエラー'));
        }));

        $response = $this->post(route('receipts.parse', $receipt));

        $response->assertRedirect(route('receipts.pending'));
        $response->assertSessionHasErrors('parse');
    }

    public function test_失敗したレシートは読み取り直せる(): void
    {
        $receipt = $this->uploadedReceipt();
        $receipt->update(['status' => 'failed', 'error_message' => '前回のエラー']);

        $this->mockParser($this->parsedResult());

        $this->post(route('receipts.parse', $receipt));

        $receipt->refresh();

        $this->assertSame('processed', $receipt->status);
        // 成功したら前回のエラーは消えること
        $this->assertNull($receipt->error_message);
    }

    public function test_登録済みのレシートは読み取り直さない(): void
    {
        $receipt = $this->uploadedReceipt();

        Transaction::create([
            'transaction_date' => '2026-08-01',
            'type' => 'expense',
            'amount' => 1000,
            'receipt_image_id' => $receipt->id,
        ]);

        // parse が呼ばれないこと自体を検証する
        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) {
            $mock->shouldNotReceive('parse');
        }));

        $response = $this->postJson(route('receipts.parse', $receipt));

        $response->assertStatus(422);
        $this->assertSame('pending', $receipt->fresh()->status);
    }

    public function test_未読み取りのレシートが一覧に並ぶ(): void
    {
        $this->uploadedReceipt();
        $this->uploadedReceipt();

        $response = $this->get(route('receipts.pending'));

        $response->assertOk();
        $response->assertSee('枚がまだ読み取れていません');
        $response->assertViewHas('unparsed', fn ($unparsed) => $unparsed->count() === 2);
    }

    public function test_読み取り済みになると読み取り待ちから消える(): void
    {
        Category::factory()->create(['name' => '食費']);
        $receipt = $this->uploadedReceipt();
        $this->mockParser($this->parsedResult());

        $this->post(route('receipts.parse', $receipt));

        $response = $this->get(route('receipts.pending'));

        $response->assertOk();
        $response->assertViewHas('unparsed', fn ($unparsed) => $unparsed->isEmpty());
        $response->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
    }

    public function test_読み取り済みのレシートは読み直さない(): void
    {
        // 読み直すと AI の課金が二重に発生し、2回目が失敗すると使えていた結果まで失う
        $receipt = $this->uploadedReceipt();
        $receipt->update([
            'status' => 'processed',
            'parsed_data' => $this->parsedResult(),
        ]);

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) {
            $mock->shouldNotReceive('parse');
        }));

        $response = $this->postJson(route('receipts.parse', $receipt));

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        $this->assertSame('processed', $receipt->fresh()->status);
    }

    public function test_人が頼めば読み取り済みでも読み直す(): void
    {
        // AI が「JSONとしては妥当だが中身の無い」結果を返すと processed で固まる。
        // 自動では読み直さないが、画面のボタンから明示的に頼まれたときは読み直す
        $receipt = $this->uploadedReceipt();
        $receipt->update([
            'status' => 'processed',
            'parsed_data' => $this->parsedResult(['total_amount' => null, 'shop_name' => null]),
        ]);

        $this->mockParser($this->parsedResult(['total_amount' => 4500]));

        $response = $this->postJson(route('receipts.parse', $receipt), ['retry' => 1]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        $this->assertSame(4500, $receipt->fresh()->parsed_data['total_amount']);
    }

    public function test_登録済みなら人が頼んでも読み直さない(): void
    {
        $category = Category::factory()->create(['name' => '食費']);
        $receipt = $this->uploadedReceipt();
        $receipt->update(['status' => 'processed', 'parsed_data' => $this->parsedResult()]);

        Transaction::factory()->create([
            'receipt_image_id' => $receipt->id,
            'category_id' => $category->id,
            'amount' => 3200,
        ]);

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) {
            $mock->shouldNotReceive('parse');
        }));

        $this->postJson(route('receipts.parse', $receipt), ['retry' => 1])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_確かめたほうがよいレシートを先に見せる(): void
    {
        // 品目の合計と総額を合わせておかないと、こちらにも警告が付いてしまう
        $clean = ['items' => [['name' => '牛乳', 'amount' => 3200]]];

        $ok = $this->uploadedReceipt();
        $ok->update(['status' => 'processed', 'parsed_data' => $this->parsedResult($clean)]);

        $suspicious = $this->uploadedReceipt();
        $suspicious->update([
            'status' => 'processed',
            'parsed_data' => $this->parsedResult($clean + ['transaction_date' => null]),
        ]);

        $response = $this->get(route('receipts.pending'));

        $response->assertOk();

        $rows = $response->viewData('rows');

        $this->assertSame($suspicious->id, $rows->first()['receipt']->id);
        $this->assertContains('日付を読み取れませんでした', $rows->first()['warnings']);
        $this->assertSame([], $rows->last()['warnings']);
    }

    public function test_同じレシートを同時に読み取らない(): void
    {
        $receipt = $this->uploadedReceipt();

        // 別のリクエストが読み取り中の状態を作る
        Cache::lock('receipt-parse:'.$receipt->id, 120)->get();

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) {
            $mock->shouldNotReceive('parse');
        }));

        $response = $this->postJson(route('receipts.parse', $receipt));

        $response->assertStatus(422);
        $this->assertSame('pending', $receipt->fresh()->status);
    }

    public function test_再読み取りに失敗しても使える解析結果は残す(): void
    {
        // 過去に読めていた解析結果を持つが status だけ failed という行（旧データ想定）
        $receipt = $this->uploadedReceipt();
        $receipt->update([
            'status' => 'failed',
            'parsed_data' => $this->parsedResult(),
        ]);

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) {
            $mock->shouldReceive('parse')->andThrow(new RuntimeException('APIエラー'));
        }));

        $this->postJson(route('receipts.parse', $receipt))->assertStatus(422);

        $receipt->refresh();

        // 一覧から消えないよう processed のまま扱う
        $this->assertSame('processed', $receipt->status);
        $this->assertSame('スーパーライフ', $receipt->parsed_data['shop_name']);
    }

    public function test_アップロード直後は自動開始の合図がつく(): void
    {
        $response = $this->post(route('receipts.store'), [
            'images' => [UploadedFile::fake()->image('receipt.jpg')],
        ]);

        // セッションのフラッシュだとリロードで消えるのでクエリ文字列に持たせている
        $response->assertRedirect(route('receipts.pending', ['autostart' => 1]));

        $this->get(route('receipts.pending', ['autostart' => 1]))
            ->assertViewHas('autoStart', true);
    }

    public function test_通常の訪問では自動開始しない(): void
    {
        $this->uploadedReceipt();

        $this->get(route('receipts.pending'))->assertViewHas('autoStart', false);
    }

    public function test_失敗したレシートの件数が渡る(): void
    {
        $receipt = $this->uploadedReceipt();
        $receipt->update(['status' => 'failed', 'error_message' => 'APIエラー']);

        $response = $this->get(route('receipts.pending'));

        $response->assertOk();
        $response->assertViewHas('failedTotal', 1);
        $response->assertSee('読み取り直す');
    }

    public function test_1日のAI読み取り上限を超えたら止まる(): void
    {
        // AI呼び出しは1枚ごとに課金される。画面が壊れて読み取りを繰り返しても
        // サーバ側で必ず頭を打たせる。
        config(['services.receipt_ai.daily_limit' => 1]);

        $first = $this->uploadedReceipt();
        $second = $this->uploadedReceipt();
        $result = $this->parsedResult();

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) use ($result) {
            // 上限に達したあとは AI を呼ばない
            $mock->shouldReceive('parse')->once()->andReturn($result);
        }));

        $this->postJson(route('receipts.parse', $first))->assertStatus(200);

        $response = $this->postJson(route('receipts.parse', $second));

        $response->assertStatus(429);
        // ルートの throttle も 429 を返すので、画面が区別できるよう理由を添える
        $response->assertJson(['reason' => 'daily_limit']);
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_上限に達しても状態は書き換えない(): void
    {
        config(['services.receipt_ai.daily_limit' => 1]);

        $first = $this->uploadedReceipt();
        $second = $this->uploadedReceipt();
        $result = $this->parsedResult();

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) use ($result) {
            $mock->shouldReceive('parse')->once()->andReturn($result);
        }));

        $this->postJson(route('receipts.parse', $first));
        $this->postJson(route('receipts.parse', $second));

        // failed にしてしまうと「再試行する」の対象になり、また課金されてしまう
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertNull($second->fresh()->error_message);
    }

    public function test_今日の読み取り枚数が画面に渡る(): void
    {
        config(['services.receipt_ai.daily_limit' => 5]);

        $receipt = $this->uploadedReceipt();
        $result = $this->parsedResult();

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) use ($result) {
            $mock->shouldReceive('parse')->once()->andReturn($result);
        }));

        $this->postJson(route('receipts.parse', $receipt));

        $this->uploadedReceipt();

        $this->get(route('receipts.pending'))
            ->assertViewHas('aiUsedToday', 1)
            ->assertViewHas('aiDailyLimit', 5);
    }

    public function test_長すぎるエラー文は切り詰めて保存する(): void
    {
        // AIのエラーはレスポンス本文（ときには画像そのもの）を含む。
        // そのまま入れると text 列に収まらず、保存が例外になって 500 を返してしまう。
        $receipt = $this->uploadedReceipt();
        $long = str_repeat('エラー詳細', 5000);

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) use ($long) {
            $mock->shouldReceive('parse')->andThrow(new RuntimeException($long));
        }));

        $this->postJson(route('receipts.parse', $receipt))->assertStatus(422);

        $stored = (string) $receipt->fresh()->error_message;

        $this->assertNotSame('', $stored);
        $this->assertLessThanOrEqual(501, mb_strlen($stored));
        $this->assertSame('failed', $receipt->fresh()->status);
    }

    public function test_制御文字だらけのエラー文も切り詰める(): void
    {
        // Str::limit は「表示幅」で切るので、幅0の制御文字だらけの本文は素通りしてしまう。
        // AIが画像などのバイナリをそのまま返してきたときがこれにあたる。
        $receipt = $this->uploadedReceipt();
        $binary = str_repeat("abc\x01\x02", 20000);

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) use ($binary) {
            $mock->shouldReceive('parse')->andThrow(new RuntimeException($binary));
        }));

        $this->postJson(route('receipts.parse', $receipt))->assertStatus(422);

        $stored = (string) $receipt->fresh()->error_message;

        $this->assertLessThanOrEqual(501, mb_strlen($stored));
        $this->assertLessThanOrEqual(4096, strlen($stored));
    }

    public function test_上限で弾いた分は使用枚数に数えない(): void
    {
        config(['services.receipt_ai.daily_limit' => 1]);

        $first = $this->uploadedReceipt();
        $second = $this->uploadedReceipt();
        $result = $this->parsedResult();

        $this->instance(ReceiptParser::class, Mockery::mock(ReceiptParser::class, function ($mock) use ($result) {
            $mock->shouldReceive('parse')->once()->andReturn($result);
        }));

        $this->postJson(route('receipts.parse', $first));
        $this->postJson(route('receipts.parse', $second));
        $this->postJson(route('receipts.parse', $second));

        // 「3 / 1 枚」のような表示にならないこと
        $this->get(route('receipts.pending'))->assertViewHas('aiUsedToday', 1);
    }

    public function test_自動開始の合図はURLから消される(): void
    {
        // location.reload() で autostart が残っていると、自動読み取りが
        // 何度でも再点火して AI 課金が止まらなくなる
        $this->uploadedReceipt();

        $response = $this->get(route('receipts.pending', ['autostart' => 1]));

        $response->assertOk();
        $response->assertSee("searchParams.delete('autostart')", false);
    }

    public function test_サーバエラーや上限超過では自動読み取りを打ち切る(): void
    {
        $this->uploadedReceipt();

        $response = $this->get(route('receipts.pending'));

        $response->assertOk();
        // 401/403/419 だけでなく 429 と 5xx でも止める
        $response->assertSee('status === 429', false);
        $response->assertSee('status >= 500', false);
        $response->assertSee('MAX_CONSECUTIVE_FAILURES', false);
    }

    public function test_未ログインでは読み取れない(): void
    {
        $receipt = $this->uploadedReceipt();

        \Illuminate\Support\Facades\Auth::logout();

        $this->post(route('receipts.parse', $receipt))->assertRedirect('/login');
        $this->assertSame('pending', $receipt->fresh()->status);
    }
}
