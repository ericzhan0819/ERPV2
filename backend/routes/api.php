<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashAccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MoneyEntryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

// Logout is intentionally outside the auth:sanctum guard so it stays
// idempotent: a client retrying after a lost/timed-out response must not be
// rejected with 401 just because the first attempt already invalidated the
// session. CSRF and session handling still apply via statefulApi().
Route::post('/logout', [AuthController::class, 'logout']);

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    // 車輛列表 / 詳情：所有角色皆可讀（財務欄位由 VehicleResource 依角色遮蔽）
    Route::get('vehicles', [VehicleController::class, 'index'])
        ->middleware('can:viewAny,App\Models\Vehicle');
    Route::get('vehicles/{vehicle}', [VehicleController::class, 'show'])
        ->middleware('can:view,vehicle');
    Route::get('vehicles/{vehicle}/money-entries', [VehicleController::class, 'moneyEntries'])
        ->middleware('can:viewMoneyEntries,vehicle');

    // 新增 / 編輯 / 上架 / 建車付款 / 列印：僅 admin、manager
    Route::post('vehicles', [VehicleController::class, 'store'])
        ->middleware('can:create,App\Models\Vehicle');
    Route::match(['put', 'patch'], 'vehicles/{vehicle}', [VehicleController::class, 'update'])
        ->middleware('can:update,vehicle');
    Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy'])
        ->middleware('can:delete,vehicle');
    Route::post('vehicles/{vehicle}/list', [VehicleController::class, 'list'])
        ->middleware('can:listVehicle,vehicle');
    Route::post('vehicles/{vehicle}/purchase-payment', [VehicleController::class, 'purchasePayment'])
        ->middleware('can:purchasePayment,vehicle');
    Route::get('vehicles/{vehicle}/print/intake', [VehicleController::class, 'printIntake'])
        ->middleware('can:print,vehicle');
    Route::get('vehicles/{vehicle}/print/closing', [VehicleController::class, 'printClosing'])
        ->middleware('can:print,vehicle');

    // 銷售流程：admin、manager、sales 皆可操作
    Route::post('vehicles/{vehicle}/reserve', [VehicleController::class, 'reserve'])
        ->middleware('can:reserve,vehicle');
    Route::post('vehicles/{vehicle}/final-payment', [VehicleController::class, 'finalPayment'])
        ->middleware('can:finalPayment,vehicle');
    Route::post('vehicles/{vehicle}/close-sale', [VehicleController::class, 'closeSale'])
        ->middleware('can:closeSale,vehicle');
    Route::post('vehicles/{vehicle}/expense', [VehicleController::class, 'expense'])
        ->middleware('can:expense,vehicle');
    Route::post('vehicles/{vehicle}/deposit', [VehicleController::class, 'deposit'])
        ->middleware('can:deposit,vehicle');
    Route::post('vehicles/{vehicle}/refund', [VehicleController::class, 'refund'])
        ->middleware('can:refund,vehicle');

    // 一般收支 CRUD：sales 尚無法操作（第 3 階段審核流程完成前一律 403）
    Route::apiResource('money-entries', MoneyEntryController::class)->middleware('role:admin,manager');

    Route::middleware('role:admin,manager')->group(function () {
        Route::get('cash-accounts/balances', [CashAccountController::class, 'balances']);
        Route::apiResource('cash-accounts', CashAccountController::class)->only(['index', 'show']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::apiResource('cash-accounts', CashAccountController::class)->only(['store', 'update', 'destroy']);
        Route::patch('cash-accounts/{cash_account}/status', [CashAccountController::class, 'updateStatus']);

        Route::apiResource('users', UserController::class);
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
        Route::patch('users/{user}/role', [UserController::class, 'updateRole']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
    });
});
