<?php

namespace Tests\Unit;

use App\Models\CommissionPlan;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\SalaryCommissionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SalaryCommissionCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private SalaryCommissionCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = app(SalaryCommissionCalculator::class);
    }

    public function test_standard_single_vehicle_formula_and_company_total_are_exact(): void
    {
        $result = $this->calculator->calculateVehicleAmounts(100000, 0, 4000, 2000, 5000);

        $this->assertSame(100000, $result['gross_profit']);
        $this->assertSame(40000, $result['company_reserve']);
        $this->assertSame(60000, $result['distributable_pool']);
        $this->assertSame(12000, $result['purchase_bonus']);
        $this->assertSame(30000, $result['sales_bonus']);
        $this->assertSame(18000, $result['company_remaining']);
        $this->assertSame(58000, $result['company_total']);
        $this->assertSame(
            $result['gross_profit'],
            $result['company_total'] + $result['purchase_bonus'] + $result['sales_bonus'],
        );
    }

    public function test_only_approved_vehicle_money_entries_are_used(): void
    {
        [$plan, $agent] = $this->standardPlanAndAgent();
        $vehicle = $this->vehicle($agent, $agent);

        $this->moneyEntry($vehicle, 'income', 120000, MoneyEntry::APPROVAL_APPROVED);
        $this->moneyEntry($vehicle, 'expense', 20000, MoneyEntry::APPROVAL_APPROVED);
        $this->moneyEntry($vehicle, 'income', 900000, MoneyEntry::APPROVAL_PENDING);
        $this->moneyEntry($vehicle, 'expense', 800000, MoneyEntry::APPROVAL_REJECTED);

        $result = $this->calculator->calculate($plan, '2026-06', [$vehicle], [$agent->id]);
        $calculation = $result['vehicles'][$vehicle->id];

        $this->assertSame(120000, $calculation['income_total']);
        $this->assertSame(20000, $calculation['expense_total']);
        $this->assertSame(100000, $calculation['gross_profit']);
        $this->assertSame(2000, $calculation['sales_bonus_bps']);
    }

    public function test_one_three_and_five_sales_apply_one_rate_to_every_vehicle_for_the_agent(): void
    {
        [$plan, $agent] = $this->standardPlanAndAgent();
        $vehicles = collect(range(1, 5))->map(function () use ($agent) {
            $vehicle = $this->vehicle($agent, $agent);
            $this->moneyEntry($vehicle, 'income', 100000, MoneyEntry::APPROVAL_APPROVED);

            return $vehicle;
        });

        $one = $this->calculator->calculate($plan, '2026-06', $vehicles->take(1), [$agent->id]);
        $three = $this->calculator->calculate($plan, '2026-06', $vehicles->take(3), [$agent->id]);
        $five = $this->calculator->calculate($plan, '2026-06', $vehicles, [$agent->id]);

        $this->assertSame([2000], array_values(array_unique(array_column($one['vehicles'], 'sales_bonus_bps'))));
        $this->assertSame([3000], array_values(array_unique(array_column($three['vehicles'], 'sales_bonus_bps'))));
        $this->assertSame([5000], array_values(array_unique(array_column($five['vehicles'], 'sales_bonus_bps'))));
        $this->assertSame(30000, $five['vehicles'][$vehicles->first()->id]['sales_bonus']);
        $this->assertSame(150000, $five['sales_agents'][$agent->id]['sales_bonus_total']);
        $this->assertNull($five['sales_agents'][$agent->id]['sales_until_next_tier']);
    }

    public function test_next_tier_distance_and_existing_vehicle_uplift_are_returned(): void
    {
        [$plan, $agent] = $this->standardPlanAndAgent();
        $vehicles = collect(range(1, 3))->map(function () use ($agent) {
            $vehicle = $this->vehicle($agent, $agent);
            $this->moneyEntry($vehicle, 'income', 100000, MoneyEntry::APPROVAL_APPROVED);

            return $vehicle;
        });

        $result = $this->calculator->calculate($plan, '2026-06', $vehicles, [$agent->id]);
        $summary = $result['sales_agents'][$agent->id];

        $this->assertSame(3, $summary['eligible_sales_count']);
        $this->assertSame(3000, $summary['sales_bonus_bps']);
        $this->assertSame(5, $summary['next_tier_min_sales_count']);
        $this->assertSame(2, $summary['sales_until_next_tier']);
        $this->assertSame(5000, $summary['next_tier_sales_bonus_bps']);
        $this->assertSame(36000, $summary['tier_upgrade_estimated_increase']);
    }

    public function test_purchase_and_sales_agents_may_be_same_or_different(): void
    {
        [$plan, $firstAgent] = $this->standardPlanAndAgent();
        $secondAgent = User::factory()->create();
        $sameAgentVehicle = $this->vehicle($firstAgent, $firstAgent);
        $differentAgentVehicle = $this->vehicle($firstAgent, $secondAgent);
        $this->moneyEntry($sameAgentVehicle, 'income', 100000, MoneyEntry::APPROVAL_APPROVED);
        $this->moneyEntry($differentAgentVehicle, 'income', 100000, MoneyEntry::APPROVAL_APPROVED);

        $result = $this->calculator->calculate(
            $plan,
            '2026-06',
            [$sameAgentVehicle, $differentAgentVehicle],
            [$firstAgent->id, $secondAgent->id],
        );

        $this->assertSame($firstAgent->id, $result['vehicles'][$sameAgentVehicle->id]['purchase_agent_id']);
        $this->assertSame($firstAgent->id, $result['vehicles'][$sameAgentVehicle->id]['sales_agent_id']);
        $this->assertSame(12000, $result['vehicles'][$sameAgentVehicle->id]['purchase_bonus']);
        $this->assertSame(12000, $result['vehicles'][$sameAgentVehicle->id]['sales_bonus']);
        $this->assertSame($firstAgent->id, $result['vehicles'][$differentAgentVehicle->id]['purchase_agent_id']);
        $this->assertSame($secondAgent->id, $result['vehicles'][$differentAgentVehicle->id]['sales_agent_id']);
    }

    public function test_zero_and_negative_profit_never_create_negative_bonuses(): void
    {
        $zero = $this->calculator->calculateVehicleAmounts(50000, 50000, 4000, 2000, 5000);
        $loss = $this->calculator->calculateVehicleAmounts(40000, 50000, 4000, 2000, 5000);

        $this->assertSame(0, $zero['gross_profit']);
        $this->assertSame(0, $zero['purchase_bonus']);
        $this->assertSame(0, $zero['sales_bonus']);
        $this->assertSame(-10000, $loss['gross_profit']);
        $this->assertSame(0, $loss['company_reserve']);
        $this->assertSame(0, $loss['purchase_bonus']);
        $this->assertSame(0, $loss['sales_bonus']);
        $this->assertSame(0, $loss['company_remaining']);
    }

    public function test_rounding_remainder_is_absorbed_by_company_remaining(): void
    {
        $result = $this->calculator->calculateVehicleAmounts(101, 0, 4000, 2000, 3000);

        $this->assertSame(40, $result['company_reserve']);
        $this->assertSame(61, $result['distributable_pool']);
        $this->assertSame(12, $result['purchase_bonus']);
        $this->assertSame(18, $result['sales_bonus']);
        $this->assertSame(31, $result['company_remaining']);
        $this->assertSame(101, $result['company_total'] + $result['purchase_bonus'] + $result['sales_bonus']);
    }

    public function test_mixed_profit_and_loss_batch_exposes_company_net_and_preserves_invariant(): void
    {
        [$plan, $agent] = $this->standardPlanAndAgent();
        $profitVehicle = $this->vehicle($agent, $agent);
        $lossVehicle = $this->vehicle($agent, $agent);
        $this->moneyEntry($profitVehicle, 'income', 100000, MoneyEntry::APPROVAL_APPROVED);
        $this->moneyEntry($lossVehicle, 'income', 40000, MoneyEntry::APPROVAL_APPROVED);
        $this->moneyEntry($lossVehicle, 'expense', 50000, MoneyEntry::APPROVAL_APPROVED);

        $result = $this->calculator->calculate(
            $plan,
            '2026-06',
            [$profitVehicle, $lossVehicle],
            [$agent->id],
        );
        $totals = $result['totals'];

        $this->assertSame(90000, $totals['gross_profit']);
        $this->assertSame(10000, $totals['loss_total']);
        $this->assertSame(76000, $totals['company_total']);
        $this->assertSame(66000, $totals['company_net']);
        $this->assertSame(12000, $totals['purchase_bonus']);
        $this->assertSame(12000, $totals['sales_bonus']);
        $this->assertSame(
            $totals['gross_profit'],
            $totals['company_net'] + $totals['purchase_bonus'] + $totals['sales_bonus'],
        );
    }

    public function test_commission_enabled_agents_are_applied_per_role_without_dropping_vehicle(): void
    {
        [$plan, $enabledAgent] = $this->standardPlanAndAgent();
        $disabledAgent = User::factory()->create();
        $purchaseEnabled = $this->vehicle($enabledAgent, $disabledAgent);
        $salesEnabled = $this->vehicle($disabledAgent, $enabledAgent);
        $this->moneyEntry($purchaseEnabled, 'income', 100000, MoneyEntry::APPROVAL_APPROVED);
        $this->moneyEntry($salesEnabled, 'income', 100000, MoneyEntry::APPROVAL_APPROVED);

        $result = $this->calculator->calculate(
            $plan,
            '2026-06',
            [$purchaseEnabled, $salesEnabled],
            [$enabledAgent->id],
        );

        $first = $result['vehicles'][$purchaseEnabled->id];
        $this->assertTrue($first['purchase_bonus_eligible']);
        $this->assertFalse($first['sales_bonus_eligible']);
        $this->assertSame(12000, $first['purchase_bonus']);
        $this->assertSame(0, $first['sales_bonus']);
        $this->assertSame(88000, $first['company_net']);

        $second = $result['vehicles'][$salesEnabled->id];
        $this->assertFalse($second['purchase_bonus_eligible']);
        $this->assertTrue($second['sales_bonus_eligible']);
        $this->assertSame(0, $second['purchase_bonus']);
        $this->assertSame(12000, $second['sales_bonus']);
        $this->assertSame(88000, $second['company_net']);
        $this->assertSame(1, $result['sales_agents'][$enabledAgent->id]['eligible_sales_count']);
        $this->assertArrayNotHasKey($disabledAgent->id, $result['sales_agents']);
    }

    public function test_calculator_rejects_vehicle_outside_declared_period(): void
    {
        [$plan, $agent] = $this->standardPlanAndAgent();
        $vehicle = $this->vehicle($agent, $agent);
        $vehicle->forceFill(['sold_at' => '2026-07-01 00:00:00'])->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('成交月份不屬於 2026-06');

        $this->calculator->calculate($plan, '2026-06', [$vehicle->fresh()], [$agent->id]);
    }

    public function test_calculator_rejects_invalid_period_format(): void
    {
        [$plan] = $this->standardPlanAndAgent();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('YYYY-MM');

        $this->calculator->calculate($plan, '2026-6', [], []);
    }

    public function test_large_amount_formula_does_not_overflow_intermediate_multiplication(): void
    {
        $result = $this->calculator->calculateVehicleAmounts(PHP_INT_MAX, 0, 4000, 2000, 5000);

        $this->assertSame(PHP_INT_MAX, $result['gross_profit']);
        $this->assertSame(PHP_INT_MAX, $result['company_total'] + $result['purchase_bonus'] + $result['sales_bonus']);
        $this->assertGreaterThan(0, $result['sales_bonus']);
    }

    public function test_overallocated_rules_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculateVehicleAmounts(100000, 0, 4000, 6000, 5000);
    }

    public function test_zero_sales_has_zero_percent_and_empty_deterministic_output(): void
    {
        [$plan] = $this->standardPlanAndAgent();

        $tier = $this->calculator->salesTierSummary($plan, 0);
        $result = $this->calculator->calculate($plan, '2026-06', [], []);

        $this->assertSame(0, $tier['sales_bonus_bps']);
        $this->assertSame(1, $tier['next_tier_min_sales_count']);
        $this->assertSame(1, $tier['sales_until_next_tier']);
        $this->assertSame([], $result['vehicles']);
        $this->assertSame([], $result['sales_agents']);
        $this->assertSame(0, $result['totals']['gross_profit']);
        $this->assertSame(0, $result['totals']['sales_bonus']);
    }

    /** @return array{CommissionPlan, User} */
    private function standardPlanAndAgent(): array
    {
        $creator = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_admin' => true]);
        $agent = User::factory()->create();
        $plan = CommissionPlan::query()->create([
            'name' => '標準方案 '.fake()->uuid(),
            'effective_from' => '2026-01-01',
            'company_reserve_bps' => 4000,
            'purchase_bonus_bps' => 2000,
            'is_active' => true,
            'created_by' => $creator->id,
        ]);
        $plan->tiers()->createMany([
            ['min_sales_count' => 1, 'sales_bonus_bps' => 2000, 'sort_order' => 1],
            ['min_sales_count' => 3, 'sales_bonus_bps' => 3000, 'sort_order' => 2],
            ['min_sales_count' => 5, 'sales_bonus_bps' => 5000, 'sort_order' => 3],
        ]);

        return [$plan->load('tiers'), $agent];
    }

    private function vehicle(User $purchaseAgent, User $salesAgent): Vehicle
    {
        return Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-06-15 12:00:00',
            'purchase_agent_id' => $purchaseAgent->id,
            'sales_agent_id' => $salesAgent->id,
        ]);
    }

    private function moneyEntry(Vehicle $vehicle, string $direction, int $amount, string $approvalStatus): MoneyEntry
    {
        return MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'direction' => $direction,
            'amount' => $amount,
            'approval_status' => $approvalStatus,
            'source_type' => MoneyEntry::SOURCE_MANUAL,
        ]);
    }
}
