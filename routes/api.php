<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MaterialRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

/*
| Mobile API v1 (docs/API.md). Every endpoint reuses the web app's gates and workflow services.
| Routes are prefixed /api by the framework.
*/
Route::prefix('v1')->name('api.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->name('login');

    Route::middleware(['auth:sanctum', 'api.usable', 'throttle:120,1'])->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('auth/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:10,1')->name('change-password');

        Route::get('dashboard', DashboardController::class)->name('dashboard');

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

        Route::get('claims/options', [ClaimController::class, 'options'])->name('claims.options');
        Route::get('claims', [ClaimController::class, 'index'])->name('claims.index');
        Route::post('claims', [ClaimController::class, 'store'])->middleware('throttle:30,1')->name('claims.store');
        Route::get('claims/{claim}', [ClaimController::class, 'show'])->whereNumber('claim')->name('claims.show');
        Route::get('claims/{claim}/receipt', [ClaimController::class, 'receipt'])->whereNumber('claim')->name('claims.receipt');
        Route::post('claims/{claim}/decision', [ClaimController::class, 'decide'])->whereNumber('claim')->middleware('throttle:60,1')->name('claims.decision');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    });
});
