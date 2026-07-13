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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleCommissionAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_options_only_include_active_users_with_active_commission_profiles(): void
    {
        $admin = User::factory()->admin()->create();
        $eligible = $this->eligibleAgent(['name' => '符合資格']);
        $disabledCommission = $this->eligibleAgent(['name' => '不參與獎金']);
        $disabledCommission->salaryProfile->update(['commission_enabled' => false]);
        $inactiveUser = $this->eligibleAgent(['name' => '停用帳號', 'is_active' => false]);
        $inactiveProfile = $this->eligibleAgent(['name' => '停用薪資設定']);
        $inactiveProfile->salaryProfile->update(['is_active' => false]);

        $response = $this->actingAs($admin, 'web')
            ->getJson('/api/vehicles/commission-agent-options')
            ->assertOk();

        $this->assertSame([$eligible->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains($inactiveUser->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_vehicle_creation_requires_eligible_purchase_agent_and_idempotency_compares_agent(): void
    {
        $admin = User::factory()->admin()->create();
        $firstAgent = $this->eligibleAgent();
        $secondAgent = $this->eligibleAgent();
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
            'purchase_agent_id' => $admin->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('purchase_agent_id');
    }

    public function test_sales_reservation_assigns_self_and_admin_must_choose_agent_with_idempotency_conflict_protection(): void
    {
        $sales = $this->eligibleAgent(['role' => User::ROLE_SALES]);
        $otherAgent = $this->eligibleAgent();
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $account = CashAccount::factory()->create(['is_active' => true]);

        $salesVehicle = Vehicle::factory()->create(['status' => 'listed']);
        $payload = $this->reservationPayload($account);
        $this->actingAs($sales, 'web')->postJson("/api/vehicles/{$salesVehicle->id}/reserve", $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.sales_agent_id', $sales->id);

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
        $purchaseAgent = $this->eligibleAgent();
        $salesAgent = $this->eligibleAgent();
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

    public function test_confirmed_or_paid_salary_period_reference_locks_vehicle_attribution(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = $this->eligibleAgent();
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
    }

    private function eligibleAgent(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        SalaryProfile::query()->create([
            'user_id' => $user->id,
            'base_salary' => 0,
            'fixed_allowance' => 0,
            'labor_insurance_deduction' => 0,
            'health_insurance_deduction' => 0,
            'commission_enabled' => true,
            'is_active' => true,
        ]);

        return $user->load('salaryProfile');
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
