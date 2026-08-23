<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = Transaction::with('category')->orderByDesc('transaction_date')->orderByDesc('id');

        // MySQL / SQLite どちらでも動くように whereYear / whereMonth を使う
        if ($month = $request->input('month')) {
            if (preg_match('/^(\\d{4})-(\\d{2})$/', $month, $matches)) {
                $query->whereYear('transaction_date', (int) $matches[1])
                    ->whereMonth('transaction_date', (int) $matches[2]);
            }
        }

        if ($categoryId = $request->input('category_id')) {
            $query->where('category_id', $categoryId);
        }

        $transactions = $query->paginate(30)->withQueryString();
        $categories = Category::orderBy('type')->orderBy('sort_order')->get();

        return view('transactions.index', compact('transactions', 'categories'));
    }

    public function create()
    {
        $categories = Category::orderBy('type')->orderBy('sort_order')->get();

        return view('transactions.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'transaction_date' => ['required', 'date'],
            'type' => ['required', 'in:income,expense'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:0'],
        ]);

        Transaction::create($validated);

        return redirect()->route('transactions.index')->with('status', '取引を登録しました。');
    }

    public function edit(Transaction $transaction)
    {
        $categories = Category::orderBy('type')->orderBy('sort_order')->get();

        return view('transactions.edit', compact('transaction', 'categories'));
    }

    public function update(Request $request, Transaction $transaction)
    {
        $validated = $request->validate([
            'transaction_date' => ['required', 'date'],
            'type' => ['required', 'in:income,expense'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:0'],
        ]);

        $transaction->update($validated);

        return redirect()->route('transactions.index')->with('status', '取引を更新しました。');
    }

    public function destroy(Transaction $transaction)
    {
        $transaction->delete();

        return redirect()->route('transactions.index')->with('status', '取引を削除しました。');
    }
}
