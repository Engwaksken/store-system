<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\MaterialRequestController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::get('forgot-password', [AuthController::class, 'showForgot'])->name('password.request');
    Route::post('forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('reset-password/{token}', [AuthController::class, 'showReset'])->name('password.reset');
    Route::post('reset-password', [AuthController::class, 'reset'])->name('password.update');
});

Route::middleware(['auth', 'usable'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('password/change', [AuthController::class, 'showChange'])->name('password.change');
    Route::put('password/change', [AuthController::class, 'change'])->name('password.change.update');

    Route::get('/', DashboardController::class)->name('dashboard');

    // Administration
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/expense-categories', [SettingsController::class, 'storeExpenseCategory'])->name('settings.expense-categories.store');
    Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

    // Projects
    Route::resource('projects', ProjectController::class)->except(['destroy']);

    // Catalog
    Route::get('catalog/setup', [CatalogController::class, 'setup'])->name('catalog.setup');
    Route::post('catalog/categories', [CatalogController::class, 'storeCategory'])->name('catalog.categories.store');
    Route::post('catalog/units', [CatalogController::class, 'storeUnit'])->name('catalog.units.store');
    Route::get('items', [CatalogController::class, 'index'])->name('items.index');
    Route::get('items/create', [CatalogController::class, 'create'])->name('items.create');
    Route::post('items', [CatalogController::class, 'store'])->name('items.store');
    Route::get('items/{item}', [CatalogController::class, 'show'])->name('items.show');
    Route::get('items/{item}/edit', [CatalogController::class, 'edit'])->name('items.edit');
    Route::put('items/{item}', [CatalogController::class, 'update'])->name('items.update');

    // Stock
    Route::get('receipts', [StockController::class, 'index'])->name('receipts.index');
    Route::get('receipts/create', [StockController::class, 'create'])->name('receipts.create');
    Route::post('receipts', [StockController::class, 'store'])->name('receipts.store');
    Route::get('receipts/opening-template', [StockController::class, 'openingTemplate'])->name('receipts.opening-template');
    Route::post('receipts/opening-import', [StockController::class, 'importOpening'])->name('receipts.opening-import');
    Route::get('receipts/{receipt}', [StockController::class, 'show'])->name('receipts.show');
    Route::get('adjustments/create', [StockController::class, 'createAdjustment'])->name('adjustments.create');
    Route::post('adjustments', [StockController::class, 'storeAdjustment'])->name('adjustments.store');

    // Material requests & approvals
    Route::get('requests', [MaterialRequestController::class, 'index'])->name('requests.index');
    Route::get('requests/create', [MaterialRequestController::class, 'create'])->name('requests.create');
    Route::post('requests', [MaterialRequestController::class, 'store'])->name('requests.store');
    Route::get('requests/{materialRequest}', [MaterialRequestController::class, 'show'])->name('requests.show');
    Route::get('requests/{materialRequest}/edit', [MaterialRequestController::class, 'edit'])->name('requests.edit');
    Route::put('requests/{materialRequest}', [MaterialRequestController::class, 'update'])->name('requests.update');
    Route::post('requests/{materialRequest}/submit', [MaterialRequestController::class, 'submit'])->name('requests.submit');
    Route::post('requests/{materialRequest}/cancel', [MaterialRequestController::class, 'cancel'])->name('requests.cancel');
    Route::post('requests/{materialRequest}/revise', [MaterialRequestController::class, 'revise'])->name('requests.revise');
    Route::post('requests/{materialRequest}/pm-decision', [MaterialRequestController::class, 'pmDecision'])->name('requests.pm-decision');
    Route::post('requests/{materialRequest}/overall-decision', [MaterialRequestController::class, 'overallDecision'])->name('requests.overall-decision');
    Route::get('approvals', [MaterialRequestController::class, 'approvals'])->name('approvals.index');

    // Issuance
    Route::get('issues', [IssueController::class, 'index'])->name('issues.index');
    Route::get('requests/{materialRequest}/issue', [IssueController::class, 'create'])->name('issues.create');
    Route::post('requests/{materialRequest}/issue', [IssueController::class, 'store'])->name('issues.store');
    Route::get('issues/{issue}', [IssueController::class, 'show'])->name('issues.show');
    Route::post('issues/{issue}/acknowledge', [IssueController::class, 'acknowledge'])->name('issues.acknowledge');

    // Expenses
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::get('expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::get('expenses/{expense}/proof', [ExpenseController::class, 'proof'])->name('expenses.proof');

    // Reports (append ?format=csv for export)
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/stock', [ReportController::class, 'stock'])->name('reports.stock');
    Route::get('reports/movements', [ReportController::class, 'movements'])->name('reports.movements');
    Route::get('reports/pending-approvals', [ReportController::class, 'pending'])->name('reports.pending');
    Route::get('reports/issued-by-project', [ReportController::class, 'issued'])->name('reports.issued');
    Route::get('reports/expenses-by-project', [ReportController::class, 'expenses'])->name('reports.expenses');
    Route::get('reports/project-cost', [ReportController::class, 'projectCost'])->name('reports.project-cost');
});
