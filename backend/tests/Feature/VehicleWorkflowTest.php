<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\UsesCommissionAttributionFixtures;
use Tests\TestCase;

class VehicleWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use UsesCommissionAttributionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommissionAttributionFixtures();
    }

    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        return parent::postJson($uri, $this->addCommissionAttributionFixtures($uri, $data), $headers, $options);
    }

    public function test_listing_a_vehicle_marks_preparation_as_completed(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing', 'is_preparation_completed' => false]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/list", [
                'asking_price' => 500000,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'listed')
            ->assertJsonPath('data.is_preparation_completed', true);

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'is_preparation_completed' => true,
        ]);
    }

    public function test_full_workflow_from_preparing_to_sold(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/list", [
                'asking_price' => 500000,
                'floor_price' => 450000,
                'listing_date' => '2026-01-01',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'listed');

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'reserved')
            ->assertJsonPath('data.buyer_name', '王小明');

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful()
            ->assertJsonPath('warning', null);

        $this->assertDatabaseHas('money_entries', [
            'vehicle_id' => $vehicle->id,
            'category' => '訂金收入',
            'amount' => 100000,
        ]);
        $this->assertDatabaseHas('money_entries', [
            'vehicle_id' => $vehicle->id,
            'category' => '尾款收入',
            'amount' => 380000,
        ]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'sold');

        $vehicle->refresh();
        $this->assertNotNull($vehicle->sold_at);
    }

    public function test_close_sale_normalizes_offset_timestamp_to_taipei_business_time(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [
                'sold_at' => '2026-06-30T16:30:00Z',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.sold_at', '2026-07-01T00:30:00+08:00');

        $this->assertSame('2026-07-01 00:30:00', $vehicle->fresh()->sold_at?->format('Y-m-d H:i:s'));
    }

    /**
     * 老闆身兼會計：sales 收訂金/尾款只會建立 pending 收款，成交結案前必須先由 admin
     * 核准入帳，且核准後的收款總額需達成交價，pending 金額不可直接關帳。
     */
    public function test_sales_reserve_and_final_payment_are_pending_and_close_sale_is_blocked_until_admin_approves(): void
    {
        $sales = User::factory()->sales()->create(['is_active' => true]);
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);

        Auth::forgetGuards();
        $this->actingAs($sales, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'reserved');

        $depositEntry = MoneyEntry::query()->where('vehicle_id', $vehicle->id)->where('category', '訂金收入')->firstOrFail();
        $this->assertSame('pending', $depositEntry->approval_status);

        Auth::forgetGuards();
        $this->actingAs($sales, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful();

        $finalPaymentEntry = MoneyEntry::query()->where('vehicle_id', $vehicle->id)->where('category', '尾款收入')->firstOrFail();
        $this->assertSame('pending', $finalPaymentEntry->approval_status);

        // pending 訂金/尾款不計入正式單車 summary。
        $summary = app(VehicleService::class)->financialSummary($vehicle->fresh());
        $this->assertSame(0, $summary['income_total']);

        // 成交結案前，approved 收款不足成交價，必須回 422。
        Auth::forgetGuards();
        $this->actingAs($sales, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertStatus(422);

        Auth::forgetGuards();
        $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$depositEntry->id}/approve")->assertSuccessful();
        $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$finalPaymentEntry->id}/approve")->assertSuccessful();

        Auth::forgetGuards();
        $this->actingAs($admin, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'sold');
    }

    /**
     * closeSale 只能用實際銷售收款（訂金/尾款）判斷是否達成交價，不可被其他與此次
     * 銷售收款無關、但恰好也是 approved income 的紀錄（例如「其他單車收入」）墊高
     * 湊到成交價而繞過門檻。
     */
    public function test_close_sale_ignores_unrelated_approved_income_when_checking_sold_price(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'reserved', 'sold_price' => 480000, 'buyer_name' => '王小明']);

        // 只有一筆遠低於成交價的訂金已核准。
        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '訂金收入',
            'amount' => 50000,
            'source_type' => 'vehicle_workflow',
            'approval_status' => 'approved',
        ]);

        // 一筆與此次銷售收款無關、但金額足以單獨湊到成交價的「其他單車收入」，
        // 即使已核准，也不應被拿來當作銷售收款。
        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '其他單車收入',
            'amount' => 500000,
            'source_type' => 'manual',
            'approval_status' => 'approved',
        ]);

        $this->actingAs($admin, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertStatus(422);

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'reserved']);
    }

    /**
     * closeSale 必須擋掉「已核准收款達成交價，但還有一筆待審退款」的情況：若放行關帳，
     * 這筆 pending 退款日後被 admin 核准時就沒有任何檢查點會擋下它，會讓一台已標記
     * sold 的車輛事後淨收款低於成交價，破壞「已關帳 = 已收足額」這個不變量。
     */
    public function test_close_sale_is_blocked_while_a_pending_refund_exists_even_if_approved_total_already_meets_sold_price(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'reserved', 'sold_price' => 480000, 'buyer_name' => '王小明']);

        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '訂金收入',
            'amount' => 100000,
            'source_type' => 'vehicle_workflow',
            'approval_status' => 'approved',
        ]);
        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '尾款收入',
            'amount' => 380000,
            'source_type' => 'vehicle_workflow',
            'approval_status' => 'approved',
        ]);
        $pendingRefund = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '退款',
            'amount' => 50000,
            'source_type' => 'vehicle_shortcut',
            'approval_status' => 'pending',
        ]);

        // approved 收款總額（480000）已達成交價，但仍有一筆待審退款尚未定案，必須擋下關帳。
        $this->actingAs($admin, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertStatus(422);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'reserved']);

        // 駁回待審退款後，關帳即可成功。
        $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$pendingRefund->id}/reject")->assertSuccessful();
        $this->actingAs($admin, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'sold');
    }

    /**
     * closeSale() 的待審檢查只擋得住「未來」的結案動作。這裡模擬本次修正部署前就已經
     * 結案、但當時仍留有一筆待審退款的既有車輛（直接用 factory 造出 sold 狀態車輛 +
     * pending 退款，繞過 closeSale 本身），驗證 approve() 本身也會獨立擋下核准，
     * 不依賴「結案當下有沒有檢查過」。
     */
    public function test_admin_cannot_approve_a_pending_refund_left_over_on_an_already_sold_vehicle(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_price' => 480000,
            'buyer_name' => '王小明',
            'sold_at' => now(),
        ]);
        $pendingRefund = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '退款',
            'amount' => 50000,
            'source_type' => 'vehicle_shortcut',
            'approval_status' => 'pending',
        ]);

        $this->actingAs($admin, 'web')
            ->patchJson("/api/money-entries/{$pendingRefund->id}/approve")
            ->assertStatus(422);

        $this->assertDatabaseHas('money_entries', ['id' => $pendingRefund->id, 'approval_status' => 'pending']);

        // 駁回不受影響：駁回不會移動任何金額，車輛狀態不需再檢查。
        $this->actingAs($admin, 'web')
            ->patchJson("/api/money-entries/{$pendingRefund->id}/reject")
            ->assertSuccessful()
            ->assertJsonPath('data.approval_status', 'rejected');
    }

    public function test_final_payment_mismatch_returns_warning_but_still_succeeds(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'reserved', 'sold_price' => 480000, 'buyer_name' => '王小明']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
            'amount' => 100000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertSuccessful();
        $this->assertNotNull($response->json('warning'));
    }

    public function test_buyer_customer_id_overrides_buyer_name_and_phone_with_the_customers_own_data(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $customer = Customer::factory()->create(['name' => '客戶端真實買家', 'phone' => '0900000002']);

        $response = $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                // 此段說明相鄰程式碼的用途與預期行為。
                'buyer_name' => '不一致的名字',
                'buyer_phone' => '0999999999',
                'buyer_customer_id' => $customer->id,
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ]);

        $response->assertSuccessful();
        $response->assertJsonPath('data.buyer_name', '客戶端真實買家');
        $response->assertJsonPath('data.buyer_phone', '0900000002');

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'buyer_customer_id' => $customer->id,
            'buyer_name' => '客戶端真實買家',
            'buyer_phone' => '0900000002',
        ]);
    }

    public function test_reservation_replay_is_not_invalidated_by_later_buyer_customer_changes(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $customer = Customer::factory()->create(['name' => '原始買家', 'phone' => '0900000002']);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'buyer_name' => '原始買家',
            'buyer_phone' => '0900000002',
            'buyer_customer_id' => $customer->id,
            'sold_price' => 480000,
            'deposit_amount' => 100000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", $payload)
            ->assertSuccessful();

        $customer->update(['name' => '更新後買家', 'phone' => '0911111111']);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.buyer_name', '原始買家')
            ->assertJsonPath('data.buyer_phone', '0900000002');

        $this->assertSame(1, MoneyEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->count());
    }

    public function test_cannot_reserve_a_vehicle_that_is_not_listed(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertStatus(422);
    }

    public function test_cannot_reserve_with_disabled_cash_account(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $cashAccount = CashAccount::factory()->create(['is_active' => false]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertStatus(422);
    }

    public function test_cannot_reserve_with_zero_sold_price(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 0,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sold_price');
    }

    public function test_cannot_close_sale_without_any_income_entry(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'reserved', 'sold_price' => 480000, 'buyer_name' => '王小明']);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertStatus(422);
    }

    public function test_final_payment_is_idempotent_for_same_key(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'amount' => 380000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
            ->assertSuccessful()
            ->assertJsonPath('warning', null);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
            ->assertSuccessful()
            ->assertJsonPath('warning', null);

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
        $this->assertSame(2, MoneyEntry::query()->where('vehicle_id', $vehicle->id)->count());
    }

    public function test_final_payment_allows_distinct_idempotency_keys_to_create_distinct_entries(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 860000, 100000);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful();

        $this->assertSame(2, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_replay_succeeds_after_sale_is_closed(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'amount' => 380000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'sold');

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
            ->assertSuccessful();

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_replay_succeeds_after_cash_account_is_deactivated(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'amount' => 380000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
            ->assertSuccessful();

        $cashAccount->update(['is_active' => false]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
            ->assertSuccessful();

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_rejects_same_idempotency_key_for_another_vehicle(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicleA = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $vehicleB = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicleA->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicleB->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(0, MoneyEntry::query()
            ->where('vehicle_id', $vehicleB->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_rejects_same_idempotency_key_when_payload_changes(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 390000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_replays_when_retry_omits_entry_date_even_though_first_call_set_it(): void
    {
        // 此段說明相鄰程式碼的用途與預期行為。
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
                'entry_date' => '2026-07-01',
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSuccessful()
            ->assertJsonPath('warning', null);

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
        $this->assertSame('2026-07-01', MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->first()
            ->entry_date
            ->toDateString());
    }

    public function test_final_payment_rejects_same_idempotency_key_when_explicit_entry_date_differs(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
                'entry_date' => '2026-07-01',
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
                'entry_date' => '2026-07-02',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_replays_across_midnight_when_entry_date_always_omitted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-03 23:59:00'));

        try {
            $user = User::factory()->admin()->create(['is_active' => true]);
            $cashAccount = CashAccount::factory()->create(['is_active' => true]);
            $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
            $idempotencyKey = (string) Str::uuid();

            $payload = [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ];

            $this->actingAs($user, 'web')
                ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
                ->assertSuccessful();

            Carbon::setTestNow(Carbon::parse('2026-07-04 00:01:00'));

            $this->actingAs($user, 'web')
                ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
                ->assertSuccessful()
                ->assertJsonPath('warning', null);

            $this->assertSame(1, MoneyEntry::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('category', '尾款收入')
                ->count());
            $this->assertSame('2026-07-03', MoneyEntry::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('category', '尾款收入')
                ->first()
                ->entry_date
                ->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_final_payment_replays_when_retry_supplies_the_stored_entry_date(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSuccessful();

        $storedEntryDate = MoneyEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first()
            ->entry_date
            ->toDateString();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
                'entry_date' => $storedEntryDate,
            ])
            ->assertSuccessful()
            ->assertJsonPath('warning', null);

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_rejects_when_retry_supplies_a_different_entry_date_than_stored(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSuccessful();

        $storedEntryDate = MoneyEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first()
            ->entry_date
            ->toDateString();
        $differentDate = Carbon::parse($storedEntryDate)->addDay()->toDateString();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
                'entry_date' => $differentDate,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_replays_when_entry_date_omitted_then_explicitly_matches_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-03 10:00:00'));

        try {
            $user = User::factory()->admin()->create(['is_active' => true]);
            $cashAccount = CashAccount::factory()->create(['is_active' => true]);
            $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
            $idempotencyKey = (string) Str::uuid();

            $this->actingAs($user, 'web')
                ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                    'amount' => 380000,
                    'cash_account_id' => $cashAccount->id,
                    'idempotency_key' => $idempotencyKey,
                ])
                ->assertSuccessful();

            $this->actingAs($user, 'web')
                ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                    'amount' => 380000,
                    'cash_account_id' => $cashAccount->id,
                    'idempotency_key' => $idempotencyKey,
                    'entry_date' => '2026-07-03',
                ])
                ->assertSuccessful()
                ->assertJsonPath('warning', null);

            $this->assertSame(1, MoneyEntry::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('category', '尾款收入')
                ->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 此段說明相鄰程式碼的用途與預期行為。 */
    public function test_final_payment_replays_after_duplicate_key_error_from_same_connection_insert(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'amount' => 380000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $raced = false;
        MoneyEntry::creating(function (MoneyEntry $entry) use (&$raced, $idempotencyKey, $user) {
            if ($raced || $entry->idempotency_key !== $idempotencyKey) {
                return;
            }

            $raced = true;

            // 此段說明相鄰程式碼的用途與預期行為。
            DB::commit();

            DB::table('money_entries')->insert([
                'vehicle_id' => $entry->vehicle_id,
                'cash_account_id' => $entry->cash_account_id,
                'entry_date' => $entry->entry_date,
                'direction' => $entry->direction,
                'category' => $entry->category,
                'amount' => $entry->amount,
                'counterparty_name' => $entry->counterparty_name,
                'description' => $entry->description,
                'idempotency_key' => $entry->idempotency_key,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::beginTransaction();
        });

        try {
            $this->actingAs($user, 'web')
                ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
                ->assertSuccessful()
                ->assertJsonPath('warning', null);
        } finally {
            MoneyEntry::flushEventListeners();
        }

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    /** 此段說明相鄰程式碼的用途與預期行為。 */
    public function test_final_payment_replay_survives_mysql_repeatable_read_stale_snapshot_across_two_connections(): void
    {
        $this->markTestSkipped('Replaced by VehicleFinalPaymentMysqlConcurrencyTest, which runs a committed two-process duplicate-key race without RefreshDatabase.');

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                '此測試需要以 MySQL 作為預設連線執行（例如 DB_CONNECTION=mysql 搭配獨立可拋棄的測試資料庫），'.
                '用來重現 REPEATABLE READ 下 stale snapshot 的兩個連線競態情境；SQLite 無法重現此問題，故略過。'
            );
        }

        config(['database.connections.mysql_race' => config('database.connections.mysql')]);

        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $connB = DB::connection('mysql_race');

        try {
            $connB->beginTransaction();
            $staleBeforeCommit = $connB->table('money_entries')->where('idempotency_key', $idempotencyKey)->first();
            $this->assertNull($staleBeforeCommit);

            app(VehicleService::class)->recordFinalPayment($vehicle, [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ], $user->id);

            $staleAfterCommit = $connB->table('money_entries')->where('idempotency_key', $idempotencyKey)->first();
            $this->assertNull(
                $staleAfterCommit,
                'REPEATABLE READ 快照下，B 交易內不應看到 A 交易新提交的資料，這正是需要 rollback 後開新交易重讀的原因'
            );

            $connB->rollBack();

            $freshRead = $connB->table('money_entries')->where('idempotency_key', $idempotencyKey)->first();
            $this->assertNotNull($freshRead, 'rollback 後於新交易重讀，應能看到贏家已提交的資料');
        } finally {
            if ($connB->transactionLevel() > 0) {
                $connB->rollBack();
            }
            DB::purge('mysql_race');
        }

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_reserve_vehicle_rechecks_database_state_before_writing(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $staleVehicle = Vehicle::query()->whereKey($vehicle->id)->firstOrFail();

        Vehicle::query()->whereKey($vehicle->id)->update(['status' => 'sold']);

        try {
            app(VehicleService::class)->reserveVehicle($staleVehicle, [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
                'sales_agent_id' => $this->defaultCommissionAgent->id,
            ], $user->id);

            $this->fail('應該因為車輛狀態已變更而拋出 ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseCount('money_entries', 0);
    }

    public function test_list_vehicle_rechecks_database_state_before_writing(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $staleVehicle = Vehicle::query()->whereKey($vehicle->id)->firstOrFail();

        Vehicle::query()->whereKey($vehicle->id)->update(['status' => 'reserved']);

        try {
            app(VehicleService::class)->listVehicle($staleVehicle, [
                'asking_price' => 500000,
                'floor_price' => 450000,
                'listing_date' => '2026-01-01',
            ], User::factory()->admin()->create(['is_active' => true])->id);

            $this->fail('應該因為車輛狀態已變更而拋出 ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'reserved']);
    }

    public function test_second_reservation_on_same_vehicle_returns_422_and_keeps_single_deposit(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/list", [
                'asking_price' => 500000,
                'floor_price' => 450000,
                'listing_date' => '2026-01-01',
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertStatus(422);

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '訂金收入')
            ->count());
    }

    public function test_reserve_rejects_same_idempotency_key_when_sold_price_changes(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'buyer_phone' => '0911111111',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'buyer_phone' => '0911111111',
                'sold_price' => 500000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'sold_price' => 480000]);
        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '訂金收入')
            ->count());
    }

    public function test_reserve_rejects_same_idempotency_key_when_buyer_phone_changes(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'buyer_phone' => '0911111111',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'buyer_phone' => '0922222222',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'buyer_phone' => '0911111111']);
        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '訂金收入')
            ->count());
    }

    public function test_reserve_idempotency_key_is_required(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_reserve_retry_with_same_idempotency_key_does_not_create_second_deposit(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'buyer_name' => '王小明',
            'sold_price' => 480000,
            'deposit_amount' => 100000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'reserved');

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'reserved');

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '訂金收入')
            ->count());
    }

    public function test_reserve_retry_with_same_idempotency_key_but_different_payload_is_rejected(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 200000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '訂金收入')
            ->count());
    }

    public function test_reserve_deposit_entry_cannot_be_updated_or_deleted_via_general_money_entry_crud(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);

        $depositEntry = MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '訂金收入')
            ->firstOrFail();

        $this->actingAs($user, 'web')
            ->patchJson("/api/money-entries/{$depositEntry->id}", [
                'entry_date' => '2026-07-02',
                'direction' => 'income',
                'category' => '訂金收入',
                'amount' => 200000,
                'cash_account_id' => $cashAccount->id,
                'vehicle_id' => $vehicle->id,
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'web')
            ->deleteJson("/api/money-entries/{$depositEntry->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('money_entries', ['id' => $depositEntry->id, 'amount' => 100000]);
    }

    public function test_final_payment_entry_cannot_be_updated_or_deleted_via_general_money_entry_crud(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful();

        $finalPaymentEntry = MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->firstOrFail();

        $this->actingAs($user, 'web')
            ->patchJson("/api/money-entries/{$finalPaymentEntry->id}", [
                'entry_date' => '2026-07-02',
                'direction' => 'income',
                'category' => '尾款收入',
                'amount' => 400000,
                'cash_account_id' => $cashAccount->id,
                'vehicle_id' => $vehicle->id,
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'web')
            ->deleteJson("/api/money-entries/{$finalPaymentEntry->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('money_entries', ['id' => $finalPaymentEntry->id, 'amount' => 380000]);
    }

    public function test_closed_sale_income_entry_cannot_be_deleted_or_updated(): void
    {
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'sold');

        $depositEntry = MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '訂金收入')
            ->firstOrFail();

        $this->actingAs($user, 'web')
            ->deleteJson("/api/money-entries/{$depositEntry->id}")
            ->assertStatus(422);

        $this->actingAs($user, 'web')
            ->patchJson("/api/money-entries/{$depositEntry->id}", [
                'entry_date' => '2026-07-02',
                'direction' => 'income',
                'category' => '訂金收入',
                'amount' => 200000,
                'cash_account_id' => $cashAccount->id,
                'vehicle_id' => $vehicle->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('money_entries', ['id' => $depositEntry->id, 'amount' => 100000]);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'status' => 'sold']);
    }

    private function createReservedVehicleWithDeposit(User $user, CashAccount $cashAccount, int $soldPrice, int $depositAmount): Vehicle
    {
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/list", [
                'asking_price' => $soldPrice,
                'floor_price' => $soldPrice - 50000,
                'listing_date' => '2026-01-01',
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => $soldPrice,
                'deposit_amount' => $depositAmount,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful();

        return $vehicle->refresh();
    }
}
