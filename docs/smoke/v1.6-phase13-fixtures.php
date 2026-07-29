<?php

declare(strict_types=1);

use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\MoneyEntry;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\SalaryPeriodService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$backendPath = dirname(__DIR__, 2).'/backend';
require $backendPath.'/vendor/autoload.php';

$app = require $backendPath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.sqlite.database');
$normalizedDatabase = str_replace('\\', '/', $database);
if (app()->environment() !== 'testing'
    || DB::connection()->getDriverName() !== 'sqlite'
    || ! str_starts_with($normalizedDatabase, '/tmp/erpv2-v16-smoke-')
    || ! str_ends_with($normalizedDatabase, '.sqlite')) {
    fwrite(STDERR, "拒絕建立 fixture：只允許 testing 環境的 /tmp/erpv2-v16-smoke-*.sqlite\n");
    exit(1);
}

$admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
$manager = User::query()->updateOrCreate(
    ['email' => 'manager.v16@example.test'],
    [
        'name' => 'v1.6 測試經理',
        'username' => 'v16.manager',
        'password' => Hash::make('ManagerV16!'),
        'must_change_password' => false,
        'role' => User::ROLE_MANAGER,
        'is_admin' => false,
        'is_active' => true,
    ],
);
$sales = User::query()->updateOrCreate(
    ['email' => 'sales.v16@example.test'],
    [
        'name' => 'v1.6 測試業務',
        'username' => 'v16.sales',
        'password' => Hash::make('SalesV16!'),
        'must_change_password' => false,
        'role' => User::ROLE_SALES,
        'is_admin' => false,
        'is_active' => true,
    ],
);

foreach ([$manager, $sales] as $employee) {
    SalaryProfile::query()->updateOrCreate(
        ['user_id' => $employee->id],
        [
            'base_salary' => 32000,
            'fixed_allowance' => 2000,
            'labor_insurance_deduction' => 900,
            'health_insurance_deduction' => 600,
            'commission_enabled' => true,
            'is_active' => true,
        ],
    );
}

$seller = Customer::factory()->create([
    'name' => '王先生（賣方）',
    'phone' => '0911000001',
    'customer_type' => Customer::TYPE_SELLER,
    'source' => '介紹',
]);
$buyer = Customer::factory()->create([
    'name' => '林小姐（買方）',
    'phone' => '0922000002',
    'customer_type' => Customer::TYPE_BUYER,
    'source' => '網路',
]);
Customer::factory()->create([
    'name' => '陳先生（潛在客戶）',
    'phone' => '0933000003',
    'source' => '來店',
]);

$baseVehicle = [
    'year' => 2022,
    'mileage_km' => 36000,
    'color' => '白',
    'purchase_date' => now()->subMonths(2)->toDateString(),
    'purchase_source_type' => '個人',
    'seller_name' => $seller->name,
    'seller_phone' => $seller->phone,
    'seller_customer_id' => $seller->id,
    'purchase_price' => 520000,
    'purchase_agent_id' => $sales->id,
    'asking_price' => 668000,
    'floor_price' => 630000,
    'created_by' => $admin->id,
    'updated_by' => $admin->id,
];

$preparing = Vehicle::factory()->create($baseVehicle + [
    'stock_no' => 'V16-PREPARING',
    'status' => 'preparing',
    'brand' => 'Toyota',
    'model' => 'Corolla Altis',
    'is_preparation_completed' => false,
    'condition_note' => '待完成美容與例行保養',
]);
$listed = Vehicle::factory()->create($baseVehicle + [
    'stock_no' => 'V16-LISTED',
    'status' => 'listed',
    'brand' => 'Honda',
    'model' => 'CR-V',
    'is_preparation_completed' => true,
    'listing_date' => now()->subDays(10)->toDateString(),
]);
$reserved = Vehicle::factory()->create($baseVehicle + [
    'stock_no' => 'V16-RESERVED',
    'status' => 'reserved',
    'brand' => 'Mazda',
    'model' => 'CX-5',
    'is_preparation_completed' => true,
    'listing_date' => now()->subDays(20)->toDateString(),
    'reserved_at' => now()->subDays(2),
    'sold_price' => 650000,
    'buyer_name' => $buyer->name,
    'buyer_phone' => $buyer->phone,
    'buyer_customer_id' => $buyer->id,
    'sales_agent_id' => $sales->id,
]);
$sold = Vehicle::factory()->create($baseVehicle + [
    'stock_no' => 'V16-SOLD',
    'status' => 'sold',
    'brand' => 'Lexus',
    'model' => 'UX 250h',
    'is_preparation_completed' => true,
    'is_transfer_completed' => true,
    'is_inspection_completed' => true,
    'listing_date' => now()->subMonth()->toDateString(),
    'reserved_at' => now()->subDays(9),
    'sold_at' => now()->subDays(5),
    'sold_price' => 650000,
    'buyer_name' => $buyer->name,
    'buyer_phone' => $buyer->phone,
    'buyer_customer_id' => $buyer->id,
    'sales_agent_id' => $sales->id,
    'notes' => 'v1.6 列印與成交月份工程 smoke',
]);
Vehicle::factory()->create($baseVehicle + [
    'stock_no' => 'V16-CANCELLED',
    'status' => 'cancelled',
    'brand' => 'Nissan',
    'model' => 'Kicks',
]);

$cashAccount = CashAccount::query()->where('name', '現金')->firstOrFail();
$entry = static function (
    ?Vehicle $vehicle,
    string $direction,
    string $category,
    int $amount,
    string $approval,
    User $creator,
    string $description,
) use ($admin, $cashAccount): MoneyEntry {
    return MoneyEntry::factory()->create([
        'vehicle_id' => $vehicle?->id,
        'cash_account_id' => $cashAccount->id,
        'entry_date' => now()->toDateString(),
        'direction' => $direction,
        'category' => $category,
        'amount' => $amount,
        'description' => $description,
        'source_type' => MoneyEntry::SOURCE_MANUAL,
        'approval_status' => $approval,
        'created_by' => $creator->id,
        'updated_by' => $creator->id,
        'approved_by' => $approval === MoneyEntry::APPROVAL_APPROVED ? $admin->id : null,
        'approved_at' => $approval === MoneyEntry::APPROVAL_APPROVED ? now() : null,
    ]);
};

$entry($sold, 'expense', '購車付款', 520000, MoneyEntry::APPROVAL_APPROVED, $admin, '已核准收購成本');
$entry($sold, 'income', '尾款收入', 650000, MoneyEntry::APPROVAL_APPROVED, $admin, '已核准成交收入');
$entry($reserved, 'income', '訂金收入', 50000, MoneyEntry::APPROVAL_APPROVED, $admin, '已核准保留訂金');
$entry(null, 'expense', '整備費', 8000, MoneyEntry::APPROVAL_PENDING, $sales, '待審核支出');
$entry(null, 'income', '其他收入', 3000, MoneyEntry::APPROVAL_REJECTED, $manager, '已駁回收入');

$salaryService = $app->make(SalaryPeriodService::class);
$paidMonth = now()->subMonthsNoOverflow(2)->format('Y-m');
$confirmedMonth = now()->subMonthNoOverflow()->format('Y-m');
$draftMonth = now()->format('Y-m');

$paidPeriod = $salaryService->createDraft($admin, $paidMonth);
$paidPeriod = $salaryService->confirm($admin, $paidPeriod);
$paidPeriod = $salaryService->pay($admin, $paidPeriod, [
    'cash_account_id' => $cashAccount->id,
    'payment_date' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
    'idempotency_key' => 'v16-smoke-paid-'.$paidMonth,
]);
$confirmedPeriod = $salaryService->createDraft($admin, $confirmedMonth);
$confirmedPeriod = $salaryService->confirm($admin, $confirmedPeriod);
$draftPeriod = $salaryService->createDraft($admin, $draftMonth);

echo json_encode([
    'database' => $database,
    'users' => [
        'admin' => $admin->email,
        'manager' => $manager->email,
        'sales' => $sales->email,
    ],
    'vehicles' => [
        'preparing' => $preparing->id,
        'listed' => $listed->id,
        'reserved' => $reserved->id,
        'sold' => $sold->id,
    ],
    'salary_periods' => [
        'paid' => $paidPeriod->id,
        'confirmed' => $confirmedPeriod->id,
        'draft' => $draftPeriod->id,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
