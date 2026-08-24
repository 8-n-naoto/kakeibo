<?php

namespace Tests\Feature;

use App\Services\GeminiReceiptParser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeminiReceiptParserTest extends TestCase
{
    private string $imagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imagePath = tempnam(sys_get_temp_dir(), 'receipt').'.jpg';
        // 1x1 の最小GIF(内容はダミーで良い)
        file_put_contents($this->imagePath, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
    }

    protected function tearDown(): void
    {
        @unlink($this->imagePath);

        parent::tearDown();
    }

    /**
     * Interactions API 形式（steps[].content[].text）の応答を返す。
     */
    private function fakeResponse(string $text): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'id' => 'interaction_test',
                'model' => 'gemini-3.6-flash',
                'status' => 'completed',
                'steps' => [
                    [
                        'type' => 'model_output',
                        'content' => [['type' => 'text', 'text' => $text]],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_APIレスポンスのJSONを構造化データに変換する(): void
    {
        $this->fakeResponse(json_encode([
            'transaction_date' => '2026-08-01',
            'shop_name' => 'スーパーライフ',
            'total_amount' => 3200,
            'type' => 'expense',
            'suggested_category' => '食費',
            'items' => [['name' => '牛乳', 'amount' => 200]],
            'memo' => null,
        ], JSON_UNESCAPED_UNICODE));

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        $this->assertSame('2026-08-01', $result['transaction_date']);
        $this->assertSame('スーパーライフ', $result['shop_name']);
        $this->assertSame(3200, $result['total_amount']);
        $this->assertSame('expense', $result['type']);
        $this->assertSame('食費', $result['suggested_category']);
        $this->assertCount(1, $result['items']);
    }

    public function test_金額が文字列やカンマ付きでも整数になる(): void
    {
        $this->fakeResponse(json_encode([
            'total_amount' => '¥3,200',
            'type' => 'expense',
            'items' => [
                ['name' => '牛乳', 'amount' => '1,200'],
                ['name' => 'パン'],
                '品目ではない文字列',
            ],
        ], JSON_UNESCAPED_UNICODE));

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        $this->assertSame(3200, $result['total_amount']);
        $this->assertSame(
            [['name' => '牛乳', 'amount' => 1200], ['name' => 'パン', 'amount' => 0]],
            $result['items'],
        );
    }

    public function test_itemsが配列でなくても落ちない(): void
    {
        $this->fakeResponse(json_encode([
            'total_amount' => 500,
            'type' => 'expense',
            'items' => '牛乳 200円',
        ], JSON_UNESCAPED_UNICODE));

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        $this->assertSame([], $result['items']);
    }

    public function test_金額が読み取れなくても店名があれば結果を返す(): void
    {
        $this->fakeResponse(json_encode(['shop_name' => 'スーパーライフ', 'total_amount' => null, 'type' => 'expense'], JSON_UNESCAPED_UNICODE));

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        $this->assertNull($result['total_amount']);
        $this->assertSame('スーパーライフ', $result['shop_name']);
        // 目視で確かめるべき理由を残す
        $this->assertContains('合計金額を読み取れませんでした', $result['warnings']);
    }

    public function test_金額も店名も取れなければ失敗として扱う(): void
    {
        // JSONとしては妥当だが中身が無い応答。成功として保存すると
        // status=processed で固まり、二度とAIに読ませられなくなる
        $this->fakeResponse(json_encode(['total_amount' => null, 'type' => 'expense']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/レシートとして読み取れませんでした/');

        app(GeminiReceiptParser::class)->parse($this->imagePath);
    }

    public function test_空のJSONも失敗として扱う(): void
    {
        $this->fakeResponse('[]');

        $this->expectException(\RuntimeException::class);

        app(GeminiReceiptParser::class)->parse($this->imagePath);
    }

    public function test_品目の合計が総額と合わなければ知らせる(): void
    {
        $this->fakeResponse(json_encode([
            'transaction_date' => '2026-08-01',
            'shop_name' => 'スーパーライフ',
            'total_amount' => 3200,
            'type' => 'expense',
            'items' => [
                ['name' => '牛乳', 'amount' => 200],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        // 他の警告が混ざっていないこと（混ざると「合計が合わない」を検出できていなくても通ってしまう）
        $this->assertSame(['品目の合計（¥200）が総額（¥3,200）と合いません'], $result['warnings']);
    }

    public function test_コードブロックで囲まれた応答も解析できる(): void
    {
        $this->fakeResponse("```json\n".json_encode(['total_amount' => 500, 'type' => 'expense'])."\n```");

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        $this->assertSame(500, $result['total_amount']);
    }

    public function test_不正な種別はexpenseに補正される(): void
    {
        $this->fakeResponse(json_encode(['total_amount' => 500, 'type' => 'unknown']));

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        $this->assertSame('expense', $result['type']);
    }

    public function test_output_text形式の応答も解析できる(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'output_text' => json_encode(['total_amount' => 1200, 'type' => 'expense']),
            ], 200),
        ]);

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        $this->assertSame(1200, $result['total_amount']);
    }

    public function test_旧generateContent形式の応答も解析できる(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode(['total_amount' => 780, 'type' => 'expense'])]]]],
                ],
            ], 200),
        ]);

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        $this->assertSame(780, $result['total_amount']);
    }

    public function test_APIがエラーを返した場合は例外になる(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'bad request'], 400)]);

        $this->expectException(RuntimeException::class);

        app(GeminiReceiptParser::class)->parse($this->imagePath);
    }

    public function test_JSONとして解析できない応答は例外になる(): void
    {
        $this->fakeResponse('解析できませんでした');

        $this->expectException(RuntimeException::class);

        app(GeminiReceiptParser::class)->parse($this->imagePath);
    }

    public function test_APIキーが未設定の場合は例外になる(): void
    {
        config(['services.gemini.api_key' => null]);

        $this->expectException(RuntimeException::class);

        app(GeminiReceiptParser::class)->parse($this->imagePath);
    }

    public function test_画像がbase64で送信される(): void
    {
        $this->fakeResponse(json_encode(['total_amount' => 100, 'type' => 'expense']));

        app(GeminiReceiptParser::class)->parse($this->imagePath);

        Http::assertSent(function (Request $request) {
            $data = $request->data();
            $image = $data['input'][1];

            return $request->hasHeader('x-goog-api-key')
                && $data['model'] === config('services.gemini.model')
                && $data['input'][0]['type'] === 'text'
                && $image['type'] === 'image'
                && $image['mime_type'] === 'image/jpeg'
                && ! empty($image['data']);
        });
    }

    public function test_税抜きの品目と税込みの総額は食い違い扱いにしない(): void
    {
        // 日本のレシートは品目が税抜き・総額が税込みのことが多い（差は約9%）。
        // ここで警告を出すとほぼ全部のレシートが黄色くなって意味がなくなる
        $this->fakeResponse(json_encode([
            'transaction_date' => '2026-08-01',
            'shop_name' => 'スーパーライフ',
            'total_amount' => 1100,
            'type' => 'expense',
            'items' => [['name' => '牛乳', 'amount' => 1000]],
        ], JSON_UNESCAPED_UNICODE));

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        $this->assertSame([], $result['warnings']);
    }

    public function test_値引き行の符号を落とさない(): void
    {
        // 符号を落とすと 1000+100=1100 と総額900が食い違い、
        // 品目ごとの登録では値引きが+100円の支出になってしまう
        $this->fakeResponse(json_encode([
            'transaction_date' => '2026-08-01',
            'shop_name' => 'スーパーライフ',
            'total_amount' => 900,
            'type' => 'expense',
            'items' => [
                ['name' => '牛乳', 'amount' => 1000],
                ['name' => '会員値引', 'amount' => -100],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        $this->assertSame(-100, $result['items'][1]['amount']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_品目の金額が全部読めなければ知らせる(): void
    {
        $this->fakeResponse(json_encode([
            'transaction_date' => '2026-08-01',
            'shop_name' => 'スーパーライフ',
            'total_amount' => 3200,
            'type' => 'expense',
            'items' => [['name' => '牛乳', 'amount' => null], ['name' => 'パン', 'amount' => null]],
        ], JSON_UNESCAPED_UNICODE));

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        $this->assertContains('品目の金額を読み取れませんでした', $result['warnings']);
    }

    public function test_総額0円は読み取れなかった扱いにする(): void
    {
        $this->fakeResponse(json_encode([
            'transaction_date' => '2026-08-01',
            'shop_name' => 'スーパーライフ',
            'total_amount' => 0,
            'type' => 'expense',
        ], JSON_UNESCAPED_UNICODE));

        $result = app(GeminiReceiptParser::class)->parse($this->imagePath);

        $this->assertContains('合計金額を読み取れませんでした', $result['warnings']);
    }
}
