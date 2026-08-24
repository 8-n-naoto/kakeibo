<?php

namespace Tests\Feature;

use App\Services\ClaudeReceiptParser;
use App\Services\GeminiReceiptParser;
use App\Services\ReceiptParser;
use InvalidArgumentException;
use Tests\TestCase;

class ReceiptParserDriverTest extends TestCase
{
    public function test_既定ではGeminiが使われる(): void
    {
        config(['services.receipt_ai.driver' => 'gemini']);

        $this->assertInstanceOf(GeminiReceiptParser::class, app(ReceiptParser::class));
    }

    public function test_driverをclaudeにするとClaudeが使われる(): void
    {
        config(['services.receipt_ai.driver' => 'claude']);

        $this->assertInstanceOf(ClaudeReceiptParser::class, app(ReceiptParser::class));
    }

    public function test_未知のdriverは例外になる(): void
    {
        config(['services.receipt_ai.driver' => 'unknown']);

        $this->expectException(InvalidArgumentException::class);

        app(ReceiptParser::class);
    }
}
