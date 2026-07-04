<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashAccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MoneyEntryController;
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

    Route::apiResource('vehicles', VehicleController::class);
    Route::post('vehicles/{vehicle}/list', [VehicleController::class, 'list']);
    Route::post('vehicles/{vehicle}/reserve', [VehicleController::class, 'reserve']);
    Route::post('vehicles/{vehicle}/final-payment', [VehicleController::class, 'finalPayment']);
    Route::post('vehicles/{vehicle}/close-sale', [VehicleController::class, 'closeSale']);
    Route::post('vehicles/{vehicle}/purchase-payment', [VehicleController::class, 'purchasePayment']);
    Route::post('vehicles/{vehicle}/expense', [VehicleController::class, 'expense']);
    Route::post('vehicles/{vehicle}/deposit', [VehicleController::class, 'deposit']);
    Route::post('vehicles/{vehicle}/refund', [VehicleController::class, 'refund']);
    Route::get('vehicles/{vehicle}/money-entries', [VehicleController::class, 'moneyEntries']);

    Route::apiResource('money-entries', MoneyEntryController::class);

    Route::get('cash-accounts', [CashAccountController::class, 'index']);
});
