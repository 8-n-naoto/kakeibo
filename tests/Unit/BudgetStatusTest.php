<?php

namespace Tests\Unit;

use App\Services\BudgetService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BudgetStatusTest extends TestCase
{
    public static function statusProvider(): array
    {
        return [
            '未消化' => [0.0, BudgetService::STATUS_OK],
            '半分' => [50.0, BudgetService::STATUS_OK],
            '警告の直前' => [79.9, BudgetService::STATUS_OK],
            '警告の境界' => [80.0, BudgetService::STATUS_WARNING],
            'ちょうど使い切り' => [100.0, BudgetService::STATUS_WARNING],
            '超過' => [100.1, BudgetService::STATUS_OVER],
            '大幅超過' => [180.0, BudgetService::STATUS_OVER],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_消化率から状態を判定する(float $usageRate, string $expected): void
    {
        $this->assertSame($expected, BudgetService::statusOf($usageRate));
    }
}
