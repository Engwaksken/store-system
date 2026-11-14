<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CostCodeController;
use App\Http\Controllers\ExpenseClaimController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\MaterialRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectControlsController;
use App\Http\Controllers\PurchaseInvoiceController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseRequisitionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\StockReturnController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SupplierController;
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

// Per-user ceiling on requests; individual posting routes have tighter limits.
Route::middleware(['auth', 'usable', 'throttle:300,1'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('password/change', [AuthController::class, 'showChange'])->name('password.change');
    Route::put('password/change', [AuthController::class, 'change'])->name('password.change.update');

    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/{id}', [NotificationController::class, 'open'])->whereUuid('id')->name('notifications.open');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('notifications-poll', [NotificationController::class, 'poll'])->middleware('throttle:20,1')->name('notifications.poll');
    Route::get('account/notifications', [NotificationController::class, 'settings'])->name('notifications.settings');
    Route::put('account/notifications', [NotificationController::class, 'updateSettings'])->name('notifications.settings.update');
    Route::post('account/notifications/test', [NotificationController::class, 'test'])->middleware('throttle:5,1')->name('notifications.test');
    Route::post('push-subscriptions', [NotificationController::class, 'subscribe'])->middleware('throttle:20,1')->name('push.subscribe');
    Route::delete('push-subscriptions/{subscription?}', [NotificationController::class, 'unsubscribe'])->name('push.unsubscribe');

    // Administration
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/font', [SettingsController::class, 'storeFont'])->middleware('throttle:10,1')->name('settings.font.store');
    Route::delete('settings/font', [SettingsController::class, 'destroyFont'])->name('settings.font.destroy');
    Route::post('settings/expense-categories', [SettingsController::class, 'storeExpenseCategory'])->name('settings.expense-categories.store');
    Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

    // Projects
    Route::resource('projects', ProjectController::class)->except(['destroy']);
    Route::get('projects/{project}/controls', [ProjectControlsController::class, 'show'])->name('projects.controls');
    Route::put('projects/{project}/budgets', [ProjectControlsController::class, 'saveBudgets'])->name('projects.budgets.save');
    Route::post('projects/{project}/boq', [ProjectControlsController::class, 'storeBoq'])->name('projects.boq.store');
    Route::put('projects/{project}/boq/{boq}', [ProjectControlsController::class, 'updateBoq'])->name('projects.boq.update');
    Route::delete('projects/{project}/boq/{boq}', [ProjectControlsController::class, 'destroyBoq'])->name('projects.boq.destroy');
    Route::get('projects/{project}/boq-template', [ProjectControlsController::class, 'boqTemplate'])->name('projects.boq.template');
    Route::post('projects/{project}/boq-import', [ProjectControlsController::class, 'importBoq'])->name('projects.boq.import');
    Route::post('projects/{project}/milestones', [ProjectControlsController::class, 'storeMilestone'])->name('projects.milestones.store');
    Route::put('projects/{project}/milestones/{milestone}', [ProjectControlsController::class, 'completeMilestone'])->name('projects.milestones.complete');
    Route::get('cost-codes', [CostCodeController::class, 'index'])->name('cost-codes.index');
    Route::post('cost-codes', [CostCodeController::class, 'store'])->name('cost-codes.store');
    Route::put('cost-codes/{costCode}', [CostCodeController::class, 'update'])->name('cost-codes.update');

    // Catalog
    Route::get('catalog/setup', [CatalogController::class, 'setup'])->name('catalog.setup');
    Route::post('catalog/categories', [CatalogController::class, 'storeCategory'])->name('catalog.categories.store');
    Route::post('catalog/units', [CatalogController::class, 'storeUnit'])->name('catalog.units.store');
    Route::get('items', [CatalogController::class, 'index'])->name('items.index');
    Route::get('items/import-template', [CatalogController::class, 'importTemplate'])->name('items.import-template');
    Route::post('items/import', [CatalogController::class, 'import'])->name('items.import');
    Route::get('items/create', [CatalogController::class, 'create'])->name('items.create');
    Route::post('items', [CatalogController::class, 'store'])->name('items.store');
    Route::get('items/{item}', [CatalogController::class, 'show'])->name('items.show');
    Route::get('items/{item}/edit', [CatalogController::class, 'edit'])->name('items.edit');
    Route::put('items/{item}', [CatalogController::class, 'update'])->name('items.update');

    // Stores, transfers, returns, counts (authorization inside controllers + policies; store scoping inside services)
    Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
    Route::get('stores/create', [StoreController::class, 'create'])->name('stores.create');
    Route::post('stores', [StoreController::class, 'store'])->name('stores.store');
    Route::get('stores/{store}', [StoreController::class, 'show'])->name('stores.show');
    Route::get('stores/{store}/edit', [StoreController::class, 'edit'])->name('stores.edit');
    Route::put('stores/{store}', [StoreController::class, 'update'])->name('stores.update');
    Route::get('transfers', [StockTransferController::class, 'index'])->name('transfers.index');
    Route::get('transfers/create', [StockTransferController::class, 'create'])->name('transfers.create');
    Route::post('transfers', [StockTransferController::class, 'store'])->middleware('throttle:30,1')->name('transfers.store');
    Route::get('transfers/{transfer}', [StockTransferController::class, 'show'])->name('transfers.show');
    Route::post('transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->name('transfers.receive');
    Route::post('transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('transfers.cancel');
    Route::get('returns', [StockReturnController::class, 'index'])->name('returns.index');
    Route::get('issues/{issue}/return', [StockReturnController::class, 'create'])->name('returns.create');
    Route::post('issues/{issue}/return', [StockReturnController::class, 'store'])->name('returns.store');
    Route::get('returns/{return}', [StockReturnController::class, 'show'])->name('returns.show');
    Route::get('counts', [StockCountController::class, 'index'])->name('counts.index');
    Route::get('counts/create', [StockCountController::class, 'create'])->name('counts.create');
    Route::post('counts', [StockCountController::class, 'store'])->name('counts.store');
    Route::get('counts/{count}', [StockCountController::class, 'show'])->name('counts.show');
    Route::put('counts/{count}', [StockCountController::class, 'update'])->name('counts.update');
    Route::post('counts/{count}/decide', [StockCountController::class, 'decide'])->name('counts.decide');

    // Stock receipts & adjustments
    Route::get('receipts', [StockController::class, 'index'])->name('receipts.index');
    Route::get('receipts/create', [StockController::class, 'create'])->name('receipts.create');
    Route::post('receipts', [StockController::class, 'store'])->name('receipts.store');
    Route::get('receipts/opening-template', [StockController::class, 'openingTemplate'])->name('receipts.opening-template');
    Route::post('receipts/opening-import', [StockController::class, 'importOpening'])->name('receipts.opening-import');
    Route::get('receipts/{receipt}', [StockController::class, 'show'])->name('receipts.show');
    Route::get('receipts/{receipt}/pdf', [StockController::class, 'pdf'])->name('receipts.pdf');
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
    Route::get('issues/{issue}/pdf', [IssueController::class, 'pdf'])->name('issues.pdf');
    Route::post('issues/{issue}/acknowledge', [IssueController::class, 'acknowledge'])->name('issues.acknowledge');

    // Procurement
    Route::resource('suppliers', SupplierController::class)->except(['destroy']);
    Route::get('purchase-requisitions', [PurchaseRequisitionController::class, 'index'])->name('prs.index');
    Route::get('purchase-requisitions/create', [PurchaseRequisitionController::class, 'create'])->name('prs.create');
    Route::post('purchase-requisitions', [PurchaseRequisitionController::class, 'store'])->name('prs.store');
    Route::get('purchase-requisitions/{pr}', [PurchaseRequisitionController::class, 'show'])->name('prs.show');
    Route::post('purchase-requisitions/{pr}/submit', [PurchaseRequisitionController::class, 'submit'])->name('prs.submit');
    Route::post('purchase-requisitions/{pr}/decide', [PurchaseRequisitionController::class, 'decide'])->name('prs.decide');
    Route::post('purchase-requisitions/{pr}/cancel', [PurchaseRequisitionController::class, 'cancel'])->name('prs.cancel');
    Route::post('purchase-requisitions/{pr}/quotes', [PurchaseRequisitionController::class, 'storeQuote'])->name('prs.quotes.store');
    Route::post('quotes/{quote}/select', [PurchaseRequisitionController::class, 'selectQuote'])->name('quotes.select');
    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('pos.index');
    Route::get('purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('pos.create');
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('pos.store');
    Route::get('purchase-orders/{po}', [PurchaseOrderController::class, 'show'])->name('pos.show');
    Route::get('purchase-orders/{po}/pdf', [PurchaseOrderController::class, 'pdf'])->name('pos.pdf');
    Route::post('purchase-orders/{po}/decide', [PurchaseOrderController::class, 'decide'])->name('pos.decide');
    Route::post('purchase-orders/{po}/cancel', [PurchaseOrderController::class, 'cancel'])->name('pos.cancel');
    Route::post('purchase-orders/{po}/close', [PurchaseOrderController::class, 'close'])->name('pos.close');
    Route::get('purchase-orders/{po}/receive', [PurchaseOrderController::class, 'receiveForm'])->name('pos.receive');
    Route::post('purchase-orders/{po}/receive', [PurchaseOrderController::class, 'receive'])->middleware('throttle:30,1')->name('pos.receive.store');
    Route::get('purchase-orders/{po}/invoice', [PurchaseInvoiceController::class, 'create'])->name('invoices.create');
    Route::post('purchase-orders/{po}/invoice', [PurchaseInvoiceController::class, 'store'])->name('invoices.store');
    Route::get('supplier-invoices', [PurchaseInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('supplier-invoices/{invoice}', [PurchaseInvoiceController::class, 'show'])->name('invoices.show');
    Route::get('supplier-invoices/{invoice}/attachment', [PurchaseInvoiceController::class, 'attachment'])->name('invoices.attachment');
    Route::post('supplier-invoices/{invoice}/approve-exception', [PurchaseInvoiceController::class, 'approveException'])->name('invoices.approve-exception');
    Route::post('supplier-invoices/{invoice}/payments', [PurchaseInvoiceController::class, 'pay'])->middleware('throttle:30,1')->name('invoices.pay');

    // Expense claims
    Route::get('claims', [ExpenseClaimController::class, 'index'])->name('claims.index');
    Route::get('claims/create', [ExpenseClaimController::class, 'create'])->name('claims.create');
    Route::post('claims', [ExpenseClaimController::class, 'store'])->name('claims.store');
    Route::get('claims/{claim}', [ExpenseClaimController::class, 'show'])->name('claims.show');
    Route::get('claims/{claim}/receipt', [ExpenseClaimController::class, 'receipt'])->name('claims.receipt');
    Route::post('claims/{claim}/decide', [ExpenseClaimController::class, 'decide'])->name('claims.decide');
    Route::post('claims/{claim}/pay', [ExpenseClaimController::class, 'pay'])->name('claims.pay');

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
    Route::get('reports/cost-codes', [ReportController::class, 'costCodes'])->name('reports.cost-codes');
    Route::get('reports/boq-consumption', [ReportController::class, 'boqConsumption'])->name('reports.boq');
    Route::get('reports/commitments', [ReportController::class, 'commitments'])->name('reports.commitments');
});
