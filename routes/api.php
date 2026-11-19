<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\IssueController;
use App\Http\Controllers\Api\MaterialRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PurchasingController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\StoreOpsController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
| Mobile API v1 (docs/API.md). Every endpoint reuses the web app's gates and workflow services.
| Routes are prefixed /api by the framework.
*/
Route::prefix('v1')->name('api.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->name('login');
    Route::post('auth/biometric-login', [AuthController::class, 'biometricLogin'])->name('biometric-login');
    Route::post('auth/biometric-key/revoke', [AuthController::class, 'revokeBiometricKey'])->middleware('throttle:20,1')->name('biometric-key.revoke');
    Route::get('branding', BrandingController::class)->name('branding');

    Route::middleware(['auth:sanctum', 'api.usable', 'throttle:120,1'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::put('me', [AuthController::class, 'updateProfile'])->middleware('throttle:10,1')->name('me.update');
        Route::post('me/avatar', [AuthController::class, 'uploadAvatar'])->middleware('throttle:10,1')->name('me.avatar');
        Route::delete('me/avatar', [AuthController::class, 'deleteAvatar'])->middleware('throttle:10,1')->name('me.avatar.delete');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('me/biometric-key', [AuthController::class, 'createBiometricKey'])->middleware('throttle:10,1')->name('me.biometric-key');
        Route::post('auth/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:10,1')->name('change-password');

        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('reports', ReportController::class)->middleware('throttle:30,1')->name('reports');

        Route::get('approvals', [MaterialRequestController::class, 'approvals'])->name('approvals');
        Route::get('requests/options', [MaterialRequestController::class, 'options'])->name('requests.options');
        Route::get('requests', [MaterialRequestController::class, 'index'])->name('requests.index');
        Route::post('requests', [MaterialRequestController::class, 'store'])->middleware('throttle:30,1')->name('requests.store');
        Route::get('requests/{materialRequest}', [MaterialRequestController::class, 'show'])->whereNumber('materialRequest')->name('requests.show');
        Route::post('requests/{materialRequest}/decision', [MaterialRequestController::class, 'decide'])->whereNumber('materialRequest')->middleware('throttle:60,1')->name('requests.decision');
        Route::post('requests/{materialRequest}/cancel', [MaterialRequestController::class, 'cancel'])->whereNumber('materialRequest')->name('requests.cancel');

        Route::get('items', [StockController::class, 'items'])->name('items.index');
        Route::get('items/{item}', [StockController::class, 'item'])->whereNumber('item')->name('items.show');
        Route::get('stores', [StockController::class, 'stores'])->name('stores.index');
        Route::get('store-ops/options', [StoreOpsController::class, 'options'])->name('store-ops.options');

        // Issuing approved requests and issue vouchers
        Route::get('issues/queue', [IssueController::class, 'queue'])->name('issues.queue');
        Route::get('issues', [IssueController::class, 'index'])->name('issues.index');
        Route::get('issues/{issue}', [IssueController::class, 'show'])->whereNumber('issue')->name('issues.show');
        Route::post('issues/{issue}/acknowledge', [IssueController::class, 'acknowledge'])->whereNumber('issue')->middleware('throttle:30,1')->name('issues.acknowledge');
        Route::post('issues/{issue}/return', [StoreOpsController::class, 'storeReturn'])->whereNumber('issue')->middleware('throttle:30,1')->name('returns.store');
        Route::get('requests/{materialRequest}/issue', [IssueController::class, 'form'])->whereNumber('materialRequest')->name('issues.form');
        Route::post('requests/{materialRequest}/issue', [IssueController::class, 'store'])->whereNumber('materialRequest')->middleware('throttle:30,1')->name('issues.store');
        Route::post('requests/{materialRequest}/restock', [IssueController::class, 'restock'])->whereNumber('materialRequest')->middleware('throttle:30,1')->name('issues.restock');

        // Receipts, adjustments, transfers, returns
        Route::get('receipts', [StoreOpsController::class, 'receipts'])->name('receipts.index');
        Route::post('receipts', [StoreOpsController::class, 'storeReceipt'])->middleware('throttle:30,1')->name('receipts.store');
        Route::get('receipts/{receipt}', [StoreOpsController::class, 'receipt'])->whereNumber('receipt')->name('receipts.show');
        Route::post('adjustments', [StoreOpsController::class, 'storeAdjustment'])->middleware('throttle:30,1')->name('adjustments.store');
        Route::get('transfers', [StoreOpsController::class, 'transfers'])->name('transfers.index');
        Route::post('transfers', [StoreOpsController::class, 'storeTransfer'])->middleware('throttle:30,1')->name('transfers.store');
        Route::get('transfers/{transfer}', [StoreOpsController::class, 'transfer'])->whereNumber('transfer')->name('transfers.show');
        Route::post('transfers/{transfer}/receive', [StoreOpsController::class, 'receiveTransfer'])->whereNumber('transfer')->middleware('throttle:30,1')->name('transfers.receive');
        Route::post('transfers/{transfer}/cancel', [StoreOpsController::class, 'cancelTransfer'])->whereNumber('transfer')->middleware('throttle:30,1')->name('transfers.cancel');
        Route::get('returns', [StoreOpsController::class, 'returns'])->name('returns.index');
        Route::get('returns/{return}', [StoreOpsController::class, 'stockReturn'])->whereNumber('return')->name('returns.show');

        // Purchasing: view, submit, approve / reject
        Route::get('purchase-requisitions', [PurchasingController::class, 'requisitions'])->name('prs.index');
        Route::get('purchase-requisitions/{pr}', [PurchasingController::class, 'requisition'])->whereNumber('pr')->name('prs.show');
        Route::post('purchase-requisitions/{pr}/submit', [PurchasingController::class, 'submitRequisition'])->whereNumber('pr')->middleware('throttle:30,1')->name('prs.submit');
        Route::post('purchase-requisitions/{pr}/decision', [PurchasingController::class, 'decideRequisition'])->whereNumber('pr')->middleware('throttle:60,1')->name('prs.decision');
        Route::get('purchase-orders', [PurchasingController::class, 'orders'])->name('pos.index');
        Route::get('purchase-orders/{po}', [PurchasingController::class, 'order'])->whereNumber('po')->name('pos.show');
        Route::post('purchase-orders/{po}/decision', [PurchasingController::class, 'decideOrder'])->whereNumber('po')->middleware('throttle:60,1')->name('pos.decision');

        Route::get('claims/options', [ClaimController::class, 'options'])->name('claims.options');
        Route::get('claims', [ClaimController::class, 'index'])->name('claims.index');
        Route::post('claims', [ClaimController::class, 'store'])->middleware('throttle:30,1')->name('claims.store');
        Route::get('claims/{claim}', [ClaimController::class, 'show'])->whereNumber('claim')->name('claims.show');
        Route::get('claims/{claim}/receipt', [ClaimController::class, 'receipt'])->whereNumber('claim')->name('claims.receipt');
        Route::post('claims/{claim}/decision', [ClaimController::class, 'decide'])->whereNumber('claim')->middleware('throttle:60,1')->name('claims.decision');

        Route::get('users/options', [UserController::class, 'options'])->name('users.options');
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->middleware('throttle:30,1')->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
        Route::put('users/{user}', [UserController::class, 'update'])->whereNumber('user')->middleware('throttle:30,1')->name('users.update');

        Route::get('feedback/options', [FeedbackController::class, 'options'])->name('feedback.options');
        Route::get('feedback', [FeedbackController::class, 'index'])->name('feedback.index');
        Route::post('feedback', [FeedbackController::class, 'store'])->middleware('throttle:10,1')->name('feedback.store');
        Route::get('feedback/{feedback}', [FeedbackController::class, 'show'])->whereNumber('feedback')->name('feedback.show');
        Route::post('feedback/{feedback}/respond', [FeedbackController::class, 'respond'])->whereNumber('feedback')->middleware('throttle:60,1')->name('feedback.respond');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    });
});
