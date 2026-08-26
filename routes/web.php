<?php

use App\Http\Controllers\AnnualReportController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AssetSnapshotController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\BudgetSuggestionController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportBatchController;
use App\Http\Controllers\InvestmentAccountController;
use App\Http\Controllers\MerchantCategoryRuleController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\SavingsGoalController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionExportController;
use App\Http\Controllers\TransactionImportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// CSP違反レポートの受け口。ログイン画面での違反も拾いたいので認証の外に置く。
// ログを溢れさせないよう件数を絞る。
Route::post(CspReportController::path(), CspReportController::class)
    ->middleware('throttle:30,1')
    // レポートにCookieは付かない。セッションを開始すると1件ごとに空のセッションが増える。
    // CSRF検証も外す。除外リストに入れるだけでは足りない（ミドルウェア自体は動き続け、
    // 応答に XSRF-TOKEN クッキーを足そうとして $request->session() を触るため、
    // StartSession を外したこの経路は「Session store not set on request」で 500 になる）。
    // CSRFミドルウェアの**クラス名はLaravelのバージョンで変わる**（12まで ValidateCsrfToken、
    // 13から PreventRequestForgery）。片方だけ書くと除外が黙って効かなくなるので両方並べる。
    // 除外リストに存在しないクラス名が混ざっていても Laravel 側で無視される。
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery',
        'Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken',
    ])
    ->name('csp-report');

// ---- 認証（ログイン画面だけが未ログインでも開ける） ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    // コントローラ側の制限キーには送信元IPが入っているので、IPを名乗り直されると素通りする。
    // 'login' の制限は AppServiceProvider で定義していて、
    // 「メールアドレス単位（IPに依らない）10回/分」と「IP単位 20回/分」の2本立て。
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// ---- ここから下はすべてログインが必要 ----
Route::middleware('auth')->group(function () {

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

// 年間サマリー（/reports は今年、/reports/2026 のように年も指定できる）
Route::get('/reports/{year?}', AnnualReportController::class)
    ->whereNumber('year')
    ->name('reports.annual');

// CSVエクスポート/インポートは resource ルートより先に定義する
Route::get('/transactions/export', TransactionExportController::class)->name('transactions.export');

Route::get('/imports', [TransactionImportController::class, 'create'])->name('imports.create');
Route::post('/imports', [TransactionImportController::class, 'store'])->name('imports.store');
Route::get('/imports/preview', [TransactionImportController::class, 'preview'])->name('imports.preview');
Route::post('/imports/remap', [TransactionImportController::class, 'remap'])->name('imports.remap');
Route::delete('/imports/ignore-rules/{ignoreRule}', [TransactionImportController::class, 'destroyIgnoreRule'])
    ->name('imports.ignore-rules.destroy');
Route::get('/imports/batches', [ImportBatchController::class, 'index'])->name('imports.batches');
Route::delete('/imports/batches/{importBatch}', [ImportBatchController::class, 'destroy'])->name('imports.batches.destroy');
Route::post('/imports/confirm', [TransactionImportController::class, 'confirm'])->name('imports.confirm');

// 一括編集は resource ルートより先に定義する
Route::post('/transactions/bulk-update', [TransactionController::class, 'bulkUpdate'])->name('transactions.bulk-update');

Route::resource('transactions', TransactionController::class)->except(['show']);

Route::get('/receipts/upload', [ReceiptController::class, 'create'])->name('receipts.create');
Route::post('/receipts/upload', [ReceiptController::class, 'store'])->name('receipts.store');
Route::get('/receipts/pending', [ReceiptController::class, 'pending'])->name('receipts.pending');
Route::post('/receipts/pending', [ReceiptController::class, 'bulkStore'])->name('receipts.pending.store');
Route::post('/receipts/cleanup', [ReceiptController::class, 'cleanup'])->name('receipts.cleanup');
// AI呼び出しは1回ごとに課金されるので、連打で溶かさないよう上限を掛ける
Route::post('/receipts/{receiptImage}/parse', [ReceiptController::class, 'parse'])
    ->middleware('throttle:60,1')
    ->name('receipts.parse');
Route::get('/receipts/{receiptImage}/confirm', [ReceiptController::class, 'confirm'])->name('receipts.confirm');
Route::post('/receipts/{receiptImage}/confirm', [ReceiptController::class, 'confirmStore'])->name('receipts.confirm.store');

// 定期支出（計上アクションは resource ルートより先に定義する）
Route::post('/recurring/post', [RecurringTransactionController::class, 'post'])->name('recurring.post');
Route::resource('recurring', RecurringTransactionController::class)
    ->except(['show'])
    ->parameters(['recurring' => 'recurring']);

// 予算の提案は resource ルートより先に定義する
Route::get('/budgets/suggestions', [BudgetSuggestionController::class, 'index'])->name('budgets.suggestions');
Route::post('/budgets/suggestions', [BudgetSuggestionController::class, 'store'])->name('budgets.suggestions.apply');

Route::resource('budgets', BudgetController::class)->except(['show']);
Route::resource('categories', CategoryController::class)->except(['show']);

Route::get('/merchant-rules', [MerchantCategoryRuleController::class, 'index'])->name('merchant-rules.index');
Route::post('/merchant-rules', [MerchantCategoryRuleController::class, 'store'])->name('merchant-rules.store');
Route::put('/merchant-rules/{merchantRule}', [MerchantCategoryRuleController::class, 'update'])->name('merchant-rules.update');
Route::delete('/merchant-rules/{merchantRule}', [MerchantCategoryRuleController::class, 'destroy'])->name('merchant-rules.destroy');

Route::resource('assets', AssetSnapshotController::class)->except(['show']);
Route::resource('savings-goals', SavingsGoalController::class)->except(['show'])->parameters(['savings-goals' => 'savingsGoal']);
Route::resource('investment-accounts', InvestmentAccountController::class)->except(['show']);

});
