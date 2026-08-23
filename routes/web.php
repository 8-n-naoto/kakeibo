<?php

use App\Http\Controllers\AssetSnapshotController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvestmentAccountController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SavingsGoalController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionExportController;
use App\Http\Controllers\TransactionImportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

// CSVエクスポート/インポートは resource ルートより先に定義する
Route::get('/transactions/export', TransactionExportController::class)->name('transactions.export');

Route::get('/imports', [TransactionImportController::class, 'create'])->name('imports.create');
Route::post('/imports', [TransactionImportController::class, 'store'])->name('imports.store');
Route::get('/imports/preview', [TransactionImportController::class, 'preview'])->name('imports.preview');
Route::post('/imports/confirm', [TransactionImportController::class, 'confirm'])->name('imports.confirm');

Route::resource('transactions', TransactionController::class)->except(['show']);

Route::get('/receipts/upload', [ReceiptController::class, 'create'])->name('receipts.create');
Route::post('/receipts/upload', [ReceiptController::class, 'store'])->name('receipts.store');
Route::get('/receipts/{receiptImage}/confirm', [ReceiptController::class, 'confirm'])->name('receipts.confirm');
Route::post('/receipts/{receiptImage}/confirm', [ReceiptController::class, 'confirmStore'])->name('receipts.confirm.store');

Route::resource('budgets', BudgetController::class)->except(['show']);
Route::resource('categories', CategoryController::class)->except(['show']);

Route::resource('assets', AssetSnapshotController::class)->except(['show']);
Route::resource('savings-goals', SavingsGoalController::class)->except(['show'])->parameters(['savings-goals' => 'savingsGoal']);
Route::resource('investment-accounts', InvestmentAccountController::class)->except(['show']);
