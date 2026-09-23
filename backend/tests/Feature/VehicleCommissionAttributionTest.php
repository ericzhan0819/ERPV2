<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CommissionPlan;
use App\Models\MoneyEntry;
use App\Models\SalaryPeriod;
use App\Models\SalaryProfile;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleCommissionAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_options_include_active_users_without_exposing_commission_status_and_sales_cannot_read(): void
    {
        $admin = User::factory()->admin()->create();
        $withoutProfile = User::factory()->create(['name' => '尚未設定薪資']);
        $disabledCommission = User::factory()->create(['name' => '不參與獎金']);
        SalaryProfile::query()->create([
            'user_id' => $disabledCommission->id,
            'commission_enabled' => false,
            'is_active' => true,
        ]);
        $inactiveUser = User::factory()->create(['name' => '停用帳號', 'is_active' => false]);
        $sales = User::factory()->sales()->create();
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($admin, 'web')
            ->getJson('/api/vehicles/commission-agent-options')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($withoutProfile->id, $ids);
        $this->assertContains($disabledCommission->id, $ids);
        $this->assertNotContains($inactiveUser->id, $ids);
        $response->assertJsonMissingPath('data.0.commission_enabled');

        Auth::forgetGuards();
        $this->actingAs($sales, 'web')->getJson('/api/vehicles/commission-agent-options')->assertForbidden();

        Auth::forgetGuards();
        $this->actingAs($manager, 'web')->getJson('/api/vehicles/commission-agent-options')->assertOk();
    }

    public function test_vehicle_creation_accepts_active_agent_without_salary_profile_and_idempotency_compares_agent(): void
    {
        $admin = User::factory()->admin()->create();
        $firstAgent = User::factory()->create();
        $secondAgent = User::factory()->create();
        $inactiveAgent = User::factory()->create(['is_active' => false]);
        $key = (string) Str::uuid();
        $payload = [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ATTR-001',
            'purchase_agent_id' => $firstAgent->id,
            'idempotency_key' => $key,
        ];

        $this->actingAs($admin, 'web')->postJson('/api/vehicles', $payload)
            ->assertCreated()
            ->assertJsonPath('data.purchase_agent_id', $firstAgent->id);
        $this->actingAs($admin, 'web')->postJson('/api/vehicles', $payload)->assertSuccessful();

        $this->actingAs($admin, 'web')->postJson('/api/vehicles', [
            ...$payload,
            'purchase_agent_id' => $secondAgent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->actingAs($admin, 'web')->postJson('/api/vehicles', [
            ...$payload,
            'license_plate' => 'ATTR-002',
            'idempotency_key' => (string) Str::uuid(),
            'purchase_agent_id' => $inactiveAgent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('purchase_agent_id');
    }

    public function test_fresh_seeded_admin_can_create_first_vehicle_without_salary_profile_bootstrap(): void
    {
        $this->seed(AdminUserSeeder::class);
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertDatabaseMissing('salary_profiles', ['user_id' => $admin->id]);

        $this->actingAs($admin, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'BOOT-001',
            'purchase_agent_id' => $admin->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('data.purchase_agent_id', $admin->id);
    }

    public function test_non_commission_sales_can_reserve_as_self_and_admin_must_choose_active_agent_with_idempotency_protection(): void
    {
        $sales = User::factory()->sales()->create();
        SalaryProfile::query()->create([
            'user_id' => $sales->id,
            'commission_enabled' => false,
            'is_active' => true,
        ]);
        $otherAgent = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $account = CashAccount::factory()->create(['is_active' => true]);

        $salesVehicle = Vehicle::factory()->create(['status' => 'listed']);
        $payload = $this->reservationPayload($account);
        $this->actingAs($sales, 'web')->postJson("/api/vehicles/{$salesVehicle->id}/reserve", $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.sales_agent_id', $sales->id);

        Auth::forgetGuards();
        $salesWithoutProfile = User::factory()->sales()->create();
        $salesWithoutProfileVehicle = Vehicle::factory()->create(['status' => 'listed']);
        $this->actingAs($salesWithoutProfile, 'web')
            ->postJson("/api/vehicles/{$salesWithoutProfileVehicle->id}/reserve", $this->reservationPayload($account))
            ->assertSuccessful()
            ->assertJsonPath('data.sales_agent_id', $salesWithoutProfile->id);

        $adminVehicle = Vehicle::factory()->create(['status' => 'listed']);
        Auth::forgetGuards();
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$adminVehicle->id}/reserve", $this->reservationPayload($account))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sales_agent_id');

        $key = (string) Str::uuid();
        $adminPayload = [
            ...$this->reservationPayload($account),
            'sales_agent_id' => $sales->id,
            'idempotency_key' => $key,
        ];
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$adminVehicle->id}/reserve", $adminPayload)
            ->assertSuccessful();
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$adminVehicle->id}/reserve", [
            ...$adminPayload,
            'sales_agent_id' => $otherAgent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        Auth::forgetGuards();
        $managerVehicle = Vehicle::factory()->create(['status' => 'listed']);
        $this->actingAs($manager, 'web')->postJson("/api/vehicles/{$managerVehicle->id}/reserve", [
            ...$this->reservationPayload($account),
            'sales_agent_id' => $otherAgent->id,
        ])->assertSuccessful()->assertJsonPath('data.sales_agent_id', $otherAgent->id);

        Auth::forgetGuards();
        $salesImpersonationVehicle = Vehicle::factory()->create(['status' => 'listed']);
        $this->actingAs($sales, 'web')->postJson("/api/vehicles/{$salesImpersonationVehicle->id}/reserve", [
            ...$this->reservationPayload($account),
            'sales_agent_id' => $otherAgent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('sales_agent_id');
    }

    public function test_historical_vehicle_attribution_is_never_inferred_from_audit_users(): void
    {
        $creator = User::factory()->admin()->create();
        $vehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $this->assertNull($vehicle->purchase_agent_id);
        $this->assertNull($vehicle->sales_agent_id);
    }

    public function test_close_sale_requires_sales_agent(): void
    {
        $admin = User::factory()->admin()->create();
        $account = CashAccount::factory()->create();
        $vehicle = Vehicle::factory()->create([
            'status' => 'reserved',
            'sold_price' => 100000,
            'buyer_name' => '買方',
            'sales_agent_id' => null,
        ]);
        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $account->id,
            'direction' => 'income',
            'category' => '訂金收入',
            'amount' => 100000,
            'approval_status' => MoneyEntry::APPROVAL_APPROVED,
        ]);

        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sales_agent_id');
    }

    public function test_only_admin_can_list_and_patch_pending_historical_attribution_and_change_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $sales = User::factory()->sales()->create();
        $purchaseAgent = User::factory()->create();
        $salesAgent = User::factory()->create();
        $vehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-06-15',
            'purchase_agent_id' => null,
            'sales_agent_id' => null,
        ]);

        $this->actingAs($manager, 'web')->getJson('/api/vehicles/commission-attribution-pending')->assertForbidden();
        Auth::forgetGuards();
        $this->actingAs($sales, 'web')->patchJson("/api/vehicles/{$vehicle->id}/commission-attribution", [
            'sales_agent_id' => $salesAgent->id,
        ])->assertForbidden();

        Auth::forgetGuards();
        $this->actingAs($admin, 'web')->getJson('/api/vehicles/commission-attribution-pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $vehicle->id);

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicle->id}/commission-attribution", [
            'purchase_agent_id' => $purchaseAgent->id,
            'sales_agent_id' => $salesAgent->id,
        ])->assertOk();

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'purchase_agent_id' => $purchaseAgent->id,
            'sales_agent_id' => $salesAgent->id,
        ]);
        $this->assertTrue(AuditLog::query()
            ->where('subject_type', 'vehicle')
            ->where('subject_id', $vehicle->id)
            ->where('action', AuditLog::ACTION_UPDATED)
            ->exists());
    }

    public function test_admin_can_fill_missing_purchase_price_for_sold_vehicle_before_salary_confirmation(): void
    {
        $admin = User::factory()->admin()->create();
        $vehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-07-03 10:00:00',
            'purchase_price' => null,
            'license_plate' => 'SMOKE-PRICE-01',
        ]);

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'purchase_price' => 500000,
        ])->assertOk()->assertJsonPath('data.purchase_price', 500000);

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'purchase_price' => 500000,
        ]);
    }

    public function test_confirmed_salary_month_blocks_purchase_price_correction_even_without_settlement_item_reference(): void
    {
        $admin = User::factory()->admin()->create();
        $vehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-07-03 10:00:00',
            'purchase_price' => null,
            'license_plate' => 'SMOKE-PRICE-02',
        ]);
        $plan = CommissionPlan::query()->create([
            'name' => '收購價鎖定測試方案',
            'effective_from' => '2026-01-01',
            'company_reserve_bps' => 4000,
            'purchase_bonus_bps' => 2000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        SalaryPeriod::query()->create([
            'period_month' => '2026-07-01',
            'commission_plan_id' => $plan->id,
            'status' => SalaryPeriod::STATUS_CONFIRMED,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'purchase_price' => 500000,
        ])->assertUnprocessable()->assertJsonValidationErrors('purchase_price');

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'purchase_price' => null,
        ]);
    }

    public function test_confirmed_or_paid_salary_period_reference_locks_vehicle_attribution(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->create();
        $vehicle = Vehicle::factory()->create(['status' => 'sold']);
        $plan = CommissionPlan::query()->create([
            'name' => '測試方案',
            'effective_from' => '2026-01-01',
            'company_reserve_bps' => 4000,
            'purchase_bonus_bps' => 2000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $period = SalaryPeriod::query()->create([
            'period_month' => '2026-06-01',
            'commission_plan_id' => $plan->id,
            'status' => SalaryPeriod::STATUS_CONFIRMED,
            'created_by' => $admin->id,
        ]);
        $settlement = SalarySettlement::query()->create([
            'salary_period_id' => $period->id,
            'user_id' => $agent->id,
        ]);
        SalarySettlementItem::query()->create([
            'salary_settlement_id' => $settlement->id,
            'type' => SalarySettlementItem::TYPE_PURCHASE_BONUS,
            'vehicle_id' => $vehicle->id,
            'amount' => 1,
            'description' => '測試',
        ]);

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicle->id}/commission-attribution", [
            'purchase_agent_id' => $agent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('commission_attribution');

        $this->actingAs($admin, 'web')->getJson("/api/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('commission_attribution_lock.id', $period->id)
            ->assertJsonPath('commission_attribution_lock.period_month', '2026-06')
            ->assertJsonPath('commission_attribution_lock.status', SalaryPeriod::STATUS_CONFIRMED)
            ->assertJsonPath(
                'commission_attribution_lock.reason',
                '此車輛已納入已確認或已發薪的薪資月份，獎金歸屬已鎖定。',
            );

        $manager = User::factory()->manager()->create();
        Auth::forgetGuards();
        $this->actingAs($manager, 'web')->getJson("/api/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonMissingPath('commission_attribution_lock');
    }

    public function test_locked_salary_month_locks_attribution_even_without_settlement_item_reference(): void
    {
        // 兩位歸屬人都沒有 active salary profile 的合格車不會留下 settlement item，
        // 零獎金的早期資料亦同。寫入路徑依成交月份鎖定，唯讀契約必須一致。
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->create();
        $vehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-06-20 10:00:00',
        ]);
        $plan = CommissionPlan::query()->create([
            'name' => '測試方案',
            'effective_from' => '2026-01-01',
            'company_reserve_bps' => 4000,
            'purchase_bonus_bps' => 2000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $period = SalaryPeriod::query()->create([
            'period_month' => '2026-06-01',
            'commission_plan_id' => $plan->id,
            'status' => SalaryPeriod::STATUS_PAID,
            'created_by' => $admin->id,
        ]);

        $this->assertDatabaseMissing('salary_settlement_items', ['vehicle_id' => $vehicle->id]);

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicle->id}/commission-attribution", [
            'purchase_agent_id' => $agent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('commission_attribution');

        $this->actingAs($admin, 'web')->getJson("/api/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('commission_attribution_lock.id', $period->id)
            ->assertJsonPath('commission_attribution_lock.period_month', '2026-06')
            ->assertJsonPath('commission_attribution_lock.status', SalaryPeriod::STATUS_PAID);
    }

    public function test_draft_salary_month_does_not_lock_attribution_without_settlement_item_reference(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->create();
        $vehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-06-20 10:00:00',
        ]);
        $plan = CommissionPlan::query()->create([
            'name' => '測試方案',
            'effective_from' => '2026-01-01',
            'company_reserve_bps' => 4000,
            'purchase_bonus_bps' => 2000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        SalaryPeriod::query()->create([
            'period_month' => '2026-06-01',
            'commission_plan_id' => $plan->id,
            'status' => SalaryPeriod::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin, 'web')->patchJson("/api/vehicles/{$vehicle->id}/commission-attribution", [
            'purchase_agent_id' => $agent->id,
        ])->assertOk();

        $this->actingAs($admin, 'web')->getJson("/api/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('commission_attribution_lock', null);
    }

    private function reservationPayload(CashAccount $account): array
    {
        return [
            'buyer_name' => '買方',
            'sold_price' => 100000,
            'deposit_amount' => 10000,
            'cash_account_id' => $account->id,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
