<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::withCount('transactions')
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('categories.index', [
            'expenseCategories' => $categories->where('type', 'expense'),
            'incomeCategories' => $categories->where('type', 'income'),
            'natures' => Category::NATURES,
        ]);
    }

    public function create()
    {
        return view('categories.create', ['natures' => Category::NATURES]);
    }

    public function store(Request $request)
    {
        Category::create($this->validated($request));

        return redirect()->route('categories.index')->with('status', 'カテゴリを追加しました。');
    }

    public function edit(Category $category)
    {
        return view('categories.edit', [
            'category' => $category,
            'natures' => Category::NATURES,
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $category->update($this->validated($request, $category));

        return redirect()->route('categories.index')->with('status', 'カテゴリを更新しました。');
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return redirect()->route('categories.index')->with('status', 'カテゴリを削除しました。取引のカテゴリは「未分類」になります。');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Category $category = null): array
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->ignore($category?->id),
            ],
            'type' => ['required', 'in:income,expense'],
            'expense_nature' => ['nullable', Rule::in(array_keys(Category::NATURES))],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        // 収入カテゴリに固定費/変動費の区分は持たせない
        $validated['expense_nature'] = $validated['type'] === 'expense'
            ? ($validated['expense_nature'] ?? Category::NATURE_VARIABLE)
            : null;

        return $validated;
    }
}
