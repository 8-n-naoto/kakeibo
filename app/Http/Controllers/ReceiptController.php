<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ReceiptImage;
use App\Models\Transaction;
use App\Services\ReceiptParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReceiptController extends Controller
{
    public function create()
    {
        return view('receipts.upload');
    }

    /**
     * 画像をアップロードし、AI(Gemini/Claude)で解析して確認画面へ。
     */
    public function store(Request $request, ReceiptParser $parser)
    {
        $request->validate([
            'image' => ['required', 'image', 'max:10240'],
        ]);

        $path = $request->file('image')->store('receipts', 'public');

        $receiptImage = ReceiptImage::create([
            'path' => $path,
            'status' => 'pending',
        ]);

        try {
            $result = $parser->parse(Storage::disk('public')->path($path));

            $receiptImage->update([
                'status' => 'processed',
                'raw_response' => $result['raw_response'] ?? null,
            ]);

            return redirect()
                ->route('receipts.confirm', $receiptImage)
                ->with('parsed', $result);
        } catch (Throwable $e) {
            $receiptImage->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return redirect()
                ->route('receipts.create')
                ->withErrors(['image' => '画像の解析に失敗しました: '.$e->getMessage()]);
        }
    }

    /**
     * 解析結果を確認・修正して家計簿に登録する画面。
     */
    public function confirm(Request $request, ReceiptImage $receiptImage)
    {
        $parsed = $request->session()->get('parsed');
        $categories = Category::orderBy('type')->orderBy('sort_order')->get();

        // 提案カテゴリ名からIDを推測
        $suggestedCategoryId = null;
        if ($parsed && ! empty($parsed['suggested_category'])) {
            $suggestedCategoryId = $categories->firstWhere('name', $parsed['suggested_category'])?->id;
        }

        return view('receipts.confirm', [
            'receiptImage' => $receiptImage,
            'parsed' => $parsed,
            'categories' => $categories,
            'suggestedCategoryId' => $suggestedCategoryId,
        ]);
    }

    /**
     * 確認画面から実際の取引として保存。
     */
    public function confirmStore(Request $request, ReceiptImage $receiptImage)
    {
        $validated = $request->validate([
            'transaction_date' => ['required', 'date'],
            'type' => ['required', 'in:income,expense'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'shop_name' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:0'],
        ]);

        Transaction::create($validated + ['receipt_image_id' => $receiptImage->id]);

        return redirect()->route('dashboard')->with('status', 'レシートから取引を登録しました。');
    }
}
