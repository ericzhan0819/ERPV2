<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
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
});
