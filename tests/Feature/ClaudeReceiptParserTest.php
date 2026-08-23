<?php

namespace Tests\Feature;

use App\Services\ClaudeReceiptParser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ClaudeReceiptParserTest extends TestCase
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

    private function fakeResponse(string $text): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_test',
                'content' => [['type' => 'text', 'text' => $text]],
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

        $result = app(ClaudeReceiptParser::class)->parse($this->imagePath);

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

        $result = app(ClaudeReceiptParser::class)->parse($this->imagePath);

        $this->assertSame(500, $result['total_amount']);
    }

    public function test_不正な種別はexpenseに補正される(): void
    {
        $this->fakeResponse(json_encode(['total_amount' => 500, 'type' => 'unknown']));

        $result = app(ClaudeReceiptParser::class)->parse($this->imagePath);

        $this->assertSame('expense', $result['type']);
    }

    public function test_APIがエラーを返した場合は例外になる(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'bad request'], 400)]);

        $this->expectException(RuntimeException::class);

        app(ClaudeReceiptParser::class)->parse($this->imagePath);
    }

    public function test_JSONとして解析できない応答は例外になる(): void
    {
        $this->fakeResponse('解析できませんでした');

        $this->expectException(RuntimeException::class);

        app(ClaudeReceiptParser::class)->parse($this->imagePath);
    }

    public function test_APIキーが未設定の場合は例外になる(): void
    {
        config(['services.anthropic.api_key' => null]);

        $this->expectException(RuntimeException::class);

        app(ClaudeReceiptParser::class)->parse($this->imagePath);
    }

    public function test_画像がbase64で送信される(): void
    {
        $this->fakeResponse(json_encode(['total_amount' => 100, 'type' => 'expense']));

        app(ClaudeReceiptParser::class)->parse($this->imagePath);

        Http::assertSent(function (Request $request) {
            $content = $request->data()['messages'][0]['content'][0];

            return $request->hasHeader('x-api-key')
                && $content['type'] === 'image'
                && $content['source']['type'] === 'base64'
                && $content['source']['media_type'] === 'image/jpeg'
                && ! empty($content['source']['data']);
        });
    }
}
