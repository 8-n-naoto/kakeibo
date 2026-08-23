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
                'model' => 'gemini-2.5-flash',
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
}
