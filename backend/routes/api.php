<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashAccountController;
use App\Http\Controllers\CommissionPlanController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MoneyEntryController;
use App\Http\Controllers\PublicVehicleController;
use App\Http\Controllers\SalaryProfileController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehiclePhotoController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

// Logout is intentionally outside the auth:sanctum guard so it stays
// idempotent: a client retrying after a lost/timed-out response must not be
// rejected with 401 just because the first attempt already invalidated the
// session. CSRF and session handling still apply via statefulApi().
Route::post('/logout', [AuthController::class, 'logout']);

// 官網公開唯讀車輛資料：不需登入，只回傳 status=listed 車輛與公開安全欄位
// （企劃書_v1.2.md 第 10 節）。獨立於 auth:sanctum 群組之外。未登入代表無法用
// 帳號區分濫用來源，加上 IP-based throttle 避免匿名重複打大量列表/詳情請求
// 造成 DB 讀取與 JSON 序列化放大（Codex adversarial review 指出）。
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('vehicles', [PublicVehicleController::class, 'index']);
    Route::get('vehicles/{id}', [PublicVehicleController::class, 'show'])->whereNumber('id');
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    // 車輛列表 / 詳情：所有角色皆可讀（財務欄位由 VehicleResource 依角色遮蔽）
    Route::get('vehicles/commission-agent-options', [VehicleController::class, 'commissionAgentOptions'])
        ->middleware('can:viewCommissionAgentOptions,App\Models\Vehicle');
    Route::get('vehicles/commission-attribution-pending', [VehicleController::class, 'pendingCommissionAttribution'])
        ->middleware('can:viewPendingCommissionAttribution,App\Models\Vehicle');
    Route::get('vehicles', [VehicleController::class, 'index'])
        ->middleware('can:viewAny,App\Models\Vehicle');
    Route::get('vehicles/{vehicle}', [VehicleController::class, 'show'])
        ->middleware('can:view,vehicle');
    Route::get('vehicles/{vehicle}/money-entries', [VehicleController::class, 'moneyEntries'])
        ->middleware('can:viewMoneyEntries,vehicle');

    // 車輛照片：admin/manager/sales 皆可讀，僅 admin/manager 可上傳、排序、設封面、刪除
    Route::get('vehicles/{vehicle}/photos', [VehiclePhotoController::class, 'index'])
        ->middleware('can:viewPhotos,vehicle');
    Route::post('vehicles/{vehicle}/photos', [VehiclePhotoController::class, 'store'])
        ->middleware('can:managePhotos,vehicle');
    Route::patch('vehicles/{vehicle}/photos/reorder', [VehiclePhotoController::class, 'reorder'])
        ->middleware('can:managePhotos,vehicle');
    Route::patch('vehicles/{vehicle}/photos/{photo}/cover', [VehiclePhotoController::class, 'setCover'])
        ->middleware('can:managePhotos,vehicle');
    Route::delete('vehicles/{vehicle}/photos/{photo}', [VehiclePhotoController::class, 'destroy'])
        ->middleware('can:managePhotos,vehicle');

    // 新增 / 編輯 / 上架 / 建車付款 / 列印：僅 admin、manager
    Route::post('vehicles', [VehicleController::class, 'store'])
        ->middleware('can:create,App\Models\Vehicle');
    Route::match(['put', 'patch'], 'vehicles/{vehicle}', [VehicleController::class, 'update'])
        ->middleware('can:update,vehicle');
    Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy'])
        ->middleware('can:delete,vehicle');
    Route::patch('vehicles/{vehicle}/commission-attribution', [VehicleController::class, 'updateCommissionAttribution'])
        ->middleware('can:updateCommissionAttribution,vehicle');
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

    // 一般收支 CRUD：admin/manager/sales 皆可送出，manager/sales 建立進 pending 待審核。
    // show/update/destroy 額外綁定 MoneyEntryPolicy：sales 只能查詢自己送出的申請或訂金/
    // 尾款/退款等銷售收款安全紀錄（與列表 MoneyEntryService::listEntries() 同一套範圍），
    // 不得用連號 id 枚舉出其他人上報的成本紀錄；manager/sales 只能異動自己送出、尚未核准
    // 的收支，不得竄改或刪除其他 manager/sales 送出的待審收支。
    Route::apiResource('money-entries', MoneyEntryController::class)
        ->middleware('role:admin,manager,sales')
        ->middlewareFor('show', 'can:view,money_entry')
        ->middlewareFor('update', 'can:update,money_entry')
        ->middlewareFor('destroy', 'can:delete,money_entry');

    // 資金帳戶選單（不含餘額欄位）：admin、manager、sales 皆可用於收訂金 / 收尾款 / 支出登記等表單選擇帳戶
    Route::middleware('role:admin,manager,sales')->group(function () {
        Route::get('cash-accounts/options', [CashAccountController::class, 'options']);
    });

    Route::middleware('role:admin,manager')->group(function () {
        Route::get('cash-accounts/balances', [CashAccountController::class, 'balances']);
        Route::apiResource('cash-accounts', CashAccountController::class)->only(['index', 'show']);
    });

    // 客戶 CRUD：admin/manager/sales 皆可新增、編輯、查詢，僅 admin 可刪除
    Route::middleware('role:admin,manager,sales')->group(function () {
        Route::apiResource('customers', CustomerController::class)->except(['destroy']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->middleware('can:viewAny,App\Models\AuditLog');
        Route::get('audit-logs/{audit_log}', [AuditLogController::class, 'show'])
            ->middleware('can:view,audit_log');

        Route::apiResource('customers', CustomerController::class)->only(['destroy']);

        Route::apiResource('cash-accounts', CashAccountController::class)->only(['store', 'update', 'destroy']);
        Route::patch('cash-accounts/{cash_account}/status', [CashAccountController::class, 'updateStatus']);

        // 一般收支審核：只有 admin 可核准 / 駁回 pending 的 manual 收支
        Route::patch('money-entries/{money_entry}/approve', [MoneyEntryController::class, 'approve']);
        Route::patch('money-entries/{money_entry}/reject', [MoneyEntryController::class, 'reject']);

        Route::apiResource('users', UserController::class);
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
        Route::patch('users/{user}/role', [UserController::class, 'updateRole']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);

        Route::get('salary-profiles', [SalaryProfileController::class, 'index'])
            ->middleware('can:viewAny,App\Models\SalaryProfile');
        Route::put('salary-profiles/{user}', [SalaryProfileController::class, 'upsert'])
            ->middleware('can:upsert,App\Models\SalaryProfile');

        Route::get('commission-plans', [CommissionPlanController::class, 'index'])
            ->middleware('can:viewAny,App\Models\CommissionPlan');
        Route::post('commission-plans', [CommissionPlanController::class, 'store'])
            ->middleware('can:create,App\Models\CommissionPlan');
        Route::get('commission-plans/{commissionPlan}', [CommissionPlanController::class, 'show'])
            ->middleware('can:view,commissionPlan');
    });
});
