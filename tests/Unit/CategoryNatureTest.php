<?php

namespace Tests\Unit;

use App\Models\Category;
use PHPUnit\Framework\TestCase;

class CategoryNatureTest extends TestCase
{
    public function test_固定費カテゴリを判定できる(): void
    {
        $category = new Category(['name' => '住居費', 'type' => 'expense', 'expense_nature' => Category::NATURE_FIXED]);

        $this->assertTrue($category->isFixedCost());
        $this->assertSame('固定費', $category->expense_nature_label);
    }

    public function test_変動費カテゴリは固定費ではない(): void
    {
        $category = new Category(['name' => '食費', 'type' => 'expense', 'expense_nature' => Category::NATURE_VARIABLE]);

        $this->assertFalse($category->isFixedCost());
        $this->assertSame('変動費', $category->expense_nature_label);
    }

    public function test_区分未設定の支出は変動費として扱う(): void
    {
        $category = new Category(['name' => 'その他支出', 'type' => 'expense']);

        $this->assertFalse($category->isFixedCost());
        $this->assertSame('変動費', $category->expense_nature_label);
    }

    public function test_収入カテゴリには区分がない(): void
    {
        $category = new Category(['name' => '給与', 'type' => 'income']);

        $this->assertFalse($category->isFixedCost());
        $this->assertNull($category->expense_nature_label);
    }
}
