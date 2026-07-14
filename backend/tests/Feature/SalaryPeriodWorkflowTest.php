<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CommissionPlan;
use App\Models\MoneyEntry;
use App\Models\SalaryPeriod;
use App\Models\SalaryProfile;
use App\Models\SalarySettlementItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\SalaryPeriodService;
use App\Services\VehicleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalaryPeriodWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private SalaryPeriodService $service;

    private User $admin;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SalaryPeriodService::class);
        $this->admin = User::factory()->admin()->create(['is_active' => true]);
        $this->agent = User::factory()->sales()->create(['is_active' => true]);
        $this->profile($this->agent);
    }

    public function test_create_draft_uses_effective_plan_snapshots_profile_and_builds_deterministic_totals(): void
    {
        $old = $this->plan('舊方案', '2026-01-01', 1000);
        $effective = $this->plan('六月方案', '2026-06-01', 2000);
        $vehicle = $this->validVehicle();

        $period = $this->service->createDraft($this->admin, '2026-06');
        $settlement = $period->settlements->firstWhere('user_id', $this->agent->id);

        $this->assertSame($effective->id, $period->commission_plan_id);
        $this->assertNotSame($old->id, $period->commission_plan_id);
        $this->assertSame(1, $settlement->eligible_sales_count);
        $this->assertSame(2000, $settlement->sales_bonus_bps_snapshot);
        $this->assertSame(30000, $settlement->base_salary_snapshot);
        $this->assertSame(2000, $settlement->fixed_allowance_snapshot);
        $this->assertSame(8000, $settlement->purchase_bonus_total);
        $this->assertSame(8000, $settlement->sales_bonus_total);
        $this->assertSame(48000, $settlement->gross_pay);
        $this->assertSame(1500, $settlement->deduction_total);
        $this->assertSame(46500, $settlement->net_pay);
        $this->assertCount(6, $settlement->items);

        $bonus = $settlement->items->firstWhere('type', SalarySettlementItem::TYPE_SALES_BONUS);
        $this->assertSame($vehicle->id, $bonus->vehicle_id);
        $this->assertSame(100000, $bonus->calculation_snapshot['income_total']);
        $this->assertSame(60000, $bonus->calculation_snapshot['expense_total']);
        $this->assertSame('2026-06', $bonus->calculation_snapshot['period_month']);
    }

    public function test_create_draft_rejects_missing_plan_and_duplicate_month_and_excludes_users_without_active_profile(): void
    {
        $otherActiveUser = User::factory()->manager()->create(['is_active' => true]);

        $this->expectValidation(fn () => $this->service->createDraft($this->admin, '2026-06'), 'period_month');

        $this->plan('六月方案', '2026-01-01', 2000);
        $period = $this->service->createDraft($this->admin, '2026-06');
        $this->assertFalse($period->settlements->contains('user_id', $otherActiveUser->id));
        $this->expectValidation(fn () => $this->service->createDraft($this->admin, '2026-06'), 'period_month');
    }

    public function test_recalculate_keeps_bound_plan_preserves_manual_adjustments_and_reapplies_monthwide_tier(): void
    {
        $boundPlan = $this->plan('原方案', '2026-01-01', 2000, [1 => 2000, 3 => 3000]);
        $first = $this->validVehicle();
        $period = $this->service->createDraft($this->admin, '2026-06');
        $settlement = $period->settlements->firstWhere('user_id', $this->agent->id);
        $manual = $this->service->addAdjustment($this->admin, $settlement, [
            'type' => SalarySettlementItem::TYPE_MANUAL_ADDITION,
            'amount' => 500,
            'description' => '本月加給',
        ]);

        $this->plan('回溯新方案', '2026-01-01', 5000, [1 => 5000]);
        $this->validVehicle();
        $this->validVehicle();
        SalaryProfile::query()->where('user_id', $this->agent->id)->update(['base_salary' => 31000]);

        $recalculated = $this->service->recalculateDraft($this->admin, $period);
        $updated = $recalculated->settlements->firstWhere('user_id', $this->agent->id);

        $this->assertSame($boundPlan->id, $recalculated->commission_plan_id);
        $this->assertSame(3, $updated->eligible_sales_count);
        $this->assertSame(3000, $updated->sales_bonus_bps_snapshot);
        $this->assertSame(31000, $updated->base_salary_snapshot);
        $this->assertSame(500, $updated->manual_addition_total);
        $this->assertDatabaseHas('salary_settlement_items', ['id' => $manual->id, 'description' => '本月加給']);
        $this->assertSame(36000, $updated->sales_bonus_total);
        $firstSalesBonus = $updated->items->first(
            fn (SalarySettlementItem $item) => $item->vehicle_id === $first->id
                && $item->type === SalarySettlementItem::TYPE_SALES_BONUS,
        );
        $this->assertSame(12000, $firstSalesBonus->amount);
    }

    public function test_adjustments_are_admin_draft_only_positive_manual_items_and_auto_items_cannot_be_deleted(): void
    {
        $this->plan('六月方案', '2026-01-01', 2000);
        $period = $this->service->createDraft($this->admin, '2026-06');
        $settlement = $period->settlements->first();

        foreach ([$this->agent, User::factory()->manager()->create(['is_active' => true])] as $unauthorized) {
            try {
                $this->service->addAdjustment($unauthorized, $settlement, [
                    'type' => SalarySettlementItem::TYPE_MANUAL_ADDITION, 'amount' => 1, 'description' => 'x',
                ]);
                $this->fail('非 admin 不得新增薪資加扣項');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        $this->expectValidation(fn () => $this->service->addAdjustment($this->admin, $settlement, [
            'type' => SalarySettlementItem::TYPE_BASE_SALARY, 'amount' => 1, 'description' => 'x',
        ]), 'type');
        $this->expectValidation(fn () => $this->service->deleteAdjustment($this->admin, $settlement->items->first()), 'item');

        $item = $this->service->addAdjustment($this->admin, $settlement, [
            'type' => SalarySettlementItem::TYPE_MANUAL_DEDUCTION,
            'amount' => 100,
            'description' => '用品扣款',
        ]);
        $this->assertSame(1600, $settlement->fresh()->deduction_total);
        $adjustmentAudit = AuditLog::query()->where('subject_type', 'salary_settlement_item')->latest('id')->first();
        $this->assertSame(SalarySettlementItem::TYPE_MANUAL_DEDUCTION, $adjustmentAudit->after_values['type']);
        $this->assertArrayNotHasKey('amount', $adjustmentAudit->after_values);
        $this->assertArrayNotHasKey('description', $adjustmentAudit->after_values);
        $this->service->deleteAdjustment($this->admin, $item);
        $this->assertSame(1500, $settlement->fresh()->deduction_total);

        $period->update(['status' => SalaryPeriod::STATUS_CONFIRMED]);
        $this->expectValidation(fn () => $this->service->addAdjustment($this->admin, $settlement, [
            'type' => SalarySettlementItem::TYPE_MANUAL_ADDITION, 'amount' => 1, 'description' => 'x',
        ]), 'status');

        $period->update(['status' => SalaryPeriod::STATUS_PAID]);
        $this->expectValidation(fn () => $this->service->recalculateDraft($this->admin, $period), 'status');

        $unknown = User::factory()->create(['role' => 'auditor', 'is_active' => true]);
        try {
            $this->service->recalculateDraft($unknown, $period);
            $this->fail('未知角色必須 fail-closed');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_confirm_revalidates_rejects_stale_preview_then_locks_snapshot(): void
    {
        $this->plan('六月方案', '2026-01-01', 2000);
        $this->validVehicle();
        $period = $this->service->createDraft($this->admin, '2026-06');
        $lateVehicle = $this->validVehicle();

        $this->expectValidation(fn () => $this->service->confirm($this->admin, $period), 'salary_period');
        $this->assertSame(SalaryPeriod::STATUS_DRAFT, $period->fresh()->status);

        $period = $this->service->recalculateDraft($this->admin, $period);
        $confirmed = $this->service->confirm($this->admin, $period);
        $this->assertSame(SalaryPeriod::STATUS_CONFIRMED, $confirmed->status);
        $this->assertSame($this->admin->id, $confirmed->confirmed_by);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertDatabaseHas('salary_settlement_items', ['vehicle_id' => $lateVehicle->id]);
        $this->expectValidation(fn () => $this->service->recalculateDraft($this->admin, $confirmed), 'status');
    }

    public function test_confirm_blocks_current_anomalies_without_mutating_draft(): void
    {
        $this->plan('六月方案', '2026-01-01', 2000);
        $vehicle = $this->validVehicle();
        $period = $this->service->createDraft($this->admin, '2026-06');
        $vehicle->forceFill(['sales_agent_id' => null])->save();

        $this->expectValidation(fn () => $this->service->confirm($this->admin, $period), 'salary_eligibility');
        $this->assertSame(SalaryPeriod::STATUS_DRAFT, $period->fresh()->status);
    }

    public function test_close_sale_allows_draft_but_blocks_confirmed_and_paid_months_at_taipei_boundary(): void
    {
        $this->plan('六月方案', '2026-01-01', 2000);
        $period = $this->service->createDraft($this->admin, '2026-06');
        $draftVehicle = $this->closableVehicle();
        $closed = app(VehicleService::class)->closeSale($draftVehicle, ['sold_at' => '2026-06-30'], $this->admin->id);
        $this->assertSame('sold', $closed->status);

        $period->update(['status' => SalaryPeriod::STATUS_CONFIRMED]);
        $blocked = $this->closableVehicle();
        $this->expectValidation(
            fn () => app(VehicleService::class)->closeSale($blocked, ['sold_at' => '2026-06-01'], $this->admin->id),
            'sold_at',
        );

        $july = $this->service->createDraft($this->admin, '2026-07');
        $july->update(['status' => SalaryPeriod::STATUS_PAID]);
        $this->expectValidation(
            fn () => app(VehicleService::class)->closeSale($this->closableVehicle(), ['sold_at' => '2026-07-01'], $this->admin->id),
            'sold_at',
        );
    }

    public function test_confirmed_month_locks_historical_attribution_even_without_item_reference(): void
    {
        $this->plan('六月方案', '2026-01-01', 2000);
        $period = $this->service->createDraft($this->admin, '2026-06');
        $period->update(['status' => SalaryPeriod::STATUS_CONFIRMED]);
        $vehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-06-15 12:00:00',
            'purchase_agent_id' => $this->agent->id,
            'sales_agent_id' => $this->agent->id,
        ]);

        $this->expectValidation(fn () => app(VehicleService::class)->updateCommissionAttribution($vehicle, [
            'purchase_agent_id' => $this->admin->id,
            'sales_agent_id' => $this->admin->id,
        ], $this->admin->id), 'commission_attribution');
    }

    private function profile(User $user): SalaryProfile
    {
        return SalaryProfile::query()->create([
            'user_id' => $user->id,
            'base_salary' => 30000,
            'fixed_allowance' => 2000,
            'labor_insurance_deduction' => 900,
            'health_insurance_deduction' => 600,
            'commission_enabled' => true,
            'is_active' => true,
        ]);
    }

    /** @param array<int, int> $tiers */
    private function plan(string $name, string $effectiveFrom, int $firstBps, array $tiers = []): CommissionPlan
    {
        $plan = CommissionPlan::query()->create([
            'name' => $name,
            'effective_from' => $effectiveFrom,
            'company_reserve_bps' => 0,
            'purchase_bonus_bps' => 2000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $tiers = $tiers ?: [1 => $firstBps];
        foreach ($tiers as $minimum => $bps) {
            $plan->tiers()->create([
                'min_sales_count' => $minimum,
                'sales_bonus_bps' => $bps,
                'sort_order' => $plan->tiers()->count() + 1,
            ]);
        }

        return $plan;
    }

    private function validVehicle(): Vehicle
    {
        $vehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-06-15 12:00:00',
            'purchase_price' => 60000,
            'sold_price' => 100000,
            'purchase_agent_id' => $this->agent->id,
            'sales_agent_id' => $this->agent->id,
        ]);
        $this->entry($vehicle, 'expense', '購車付款', 60000);
        $this->entry($vehicle, 'income', '尾款收入', 100000);

        return $vehicle;
    }

    private function closableVehicle(): Vehicle
    {
        $vehicle = Vehicle::factory()->create([
            'status' => 'reserved',
            'sold_price' => 100000,
            'buyer_name' => '買方',
            'purchase_agent_id' => $this->agent->id,
            'sales_agent_id' => $this->agent->id,
        ]);
        $this->entry($vehicle, 'income', '尾款收入', 100000);

        return $vehicle;
    }

    private function entry(Vehicle $vehicle, string $direction, string $category, int $amount): MoneyEntry
    {
        return MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'direction' => $direction,
            'category' => $category,
            'amount' => $amount,
            'approval_status' => MoneyEntry::APPROVAL_APPROVED,
            'source_type' => MoneyEntry::SOURCE_MANUAL,
        ]);
    }

    private function expectValidation(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail("預期 {$field} 驗證錯誤");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
