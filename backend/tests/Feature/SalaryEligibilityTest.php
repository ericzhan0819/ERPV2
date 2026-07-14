<?php

namespace Tests\Feature;

use App\Models\CommissionPlan;
use App\Models\MoneyEntry;
use App\Models\SalaryPeriod;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\SalaryEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalaryEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private SalaryEligibilityService $service;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SalaryEligibilityService::class);
        $this->agent = User::factory()->create();
    }

    public function test_period_selection_excludes_non_sold_and_other_month_vehicles(): void
    {
        $eligible = $this->validVehicle();
        $notSold = $this->validVehicle(['status' => 'reserved']);
        $otherMonth = $this->validVehicle(['sold_at' => '2026-07-01 00:00:00']);

        $result = $this->service->inspectPeriod('2026-06');

        $this->assertSame([$eligible->id], $result['eligible_vehicles']->pluck('id')->all());
        $this->assertArrayNotHasKey($notSold->id, $result['vehicle_results']);
        $this->assertArrayNotHasKey($otherMonth->id, $result['vehicle_results']);
    }

    public function test_missing_purchase_and_sales_agents_are_reported_with_stock_number_and_correction_link(): void
    {
        $vehicle = $this->validVehicle();
        $vehicle->forceFill(['purchase_agent_id' => null, 'sales_agent_id' => null])->save();

        $result = $this->service->inspectPeriod('2026-06');
        $issues = $result['vehicle_results'][$vehicle->id]['issues'];

        $this->assertSame([
            SalaryEligibilityService::ISSUE_PURCHASE_AGENT_MISSING,
            SalaryEligibilityService::ISSUE_SALES_AGENT_MISSING,
        ], array_column($issues, 'code'));
        $this->assertSame($vehicle->stock_no, $issues[0]['stock_no']);
        $this->assertSame('commission_attribution', $issues[0]['correction']['action']);
        $this->assertTrue($result['has_blocking_issues']);
        $this->assertCount(0, $result['eligible_vehicles']);
    }

    public function test_any_pending_money_entry_blocks_vehicle_without_hiding_it(): void
    {
        $vehicle = $this->validVehicle();
        $this->entry($vehicle, 'expense', '維修支出', 1000, MoneyEntry::APPROVAL_PENDING);

        $result = $this->service->inspectPeriod('2026-06');

        $this->assertArrayHasKey($vehicle->id, $result['vehicle_results']);
        $this->assertFalse($result['vehicle_results'][$vehicle->id]['eligible']);
        $this->assertContains(
            SalaryEligibilityService::ISSUE_PENDING_MONEY_ENTRY,
            array_column($result['vehicle_results'][$vehicle->id]['issues'], 'code'),
        );
    }

    public function test_approved_deposit_and_final_payment_minus_refund_must_reach_sold_price(): void
    {
        $vehicle = $this->validVehicle(['sold_price' => 100000], createCollection: false);
        $this->entry($vehicle, 'income', '訂金收入', 20000);
        $this->entry($vehicle, 'income', '尾款收入', 85000);
        $this->entry($vehicle, 'expense', '退款', 5000);

        $passing = $this->service->inspectPeriod('2026-06');
        $this->assertTrue($passing['vehicle_results'][$vehicle->id]['eligible']);

        $this->entry($vehicle, 'expense', '退款', 1);
        $failing = $this->service->inspectPeriod('2026-06');

        $this->assertContains(
            SalaryEligibilityService::ISSUE_COLLECTION_SHORTFALL,
            array_column($failing['vehicle_results'][$vehicle->id]['issues'], 'code'),
        );
    }

    public function test_approved_purchase_payments_must_equal_purchase_price_exactly(): void
    {
        $vehicle = $this->validVehicle(['purchase_price' => 60000], createPurchasePayment: false);
        $this->entry($vehicle, 'expense', '購車付款', 59999);

        $result = $this->service->inspectPeriod('2026-06');

        $this->assertContains(
            SalaryEligibilityService::ISSUE_PURCHASE_PAYMENT_MISMATCH,
            array_column($result['vehicle_results'][$vehicle->id]['issues'], 'code'),
        );

        $this->entry($vehicle, 'expense', '購車付款', 1);
        $passing = $this->service->inspectPeriod('2026-06');
        $this->assertTrue($passing['vehicle_results'][$vehicle->id]['eligible']);
    }

    public function test_legacy_unknown_entry_blocks_vehicle_even_when_rejected(): void
    {
        $vehicle = $this->validVehicle();
        $this->entry(
            $vehicle,
            'expense',
            '維修支出',
            1000,
            MoneyEntry::APPROVAL_REJECTED,
            MoneyEntry::SOURCE_LEGACY_UNKNOWN,
        );

        $result = $this->service->inspectPeriod('2026-06');

        $this->assertContains(
            SalaryEligibilityService::ISSUE_LEGACY_UNKNOWN_MONEY_ENTRY,
            array_column($result['vehicle_results'][$vehicle->id]['issues'], 'code'),
        );
    }

    public function test_vehicle_cannot_be_reused_by_another_confirmed_or_paid_period(): void
    {
        $vehicle = $this->validVehicle();
        $paidVehicle = $this->validVehicle();
        $currentVehicle = $this->validVehicle();
        $confirmedPeriod = $this->period('2026-05-01', SalaryPeriod::STATUS_CONFIRMED);
        $paidPeriod = $this->period('2026-04-01', SalaryPeriod::STATUS_PAID);
        $currentPeriod = $this->period('2026-06-01', SalaryPeriod::STATUS_CONFIRMED);
        $this->referenceVehicle($confirmedPeriod, $vehicle);
        $this->referenceVehicle($paidPeriod, $paidVehicle);
        $this->referenceVehicle($currentPeriod, $currentVehicle);

        $result = $this->service->inspectPeriod('2026-06', $currentPeriod->id);

        $issue = collect($result['vehicle_results'][$vehicle->id]['issues'])
            ->firstWhere('code', SalaryEligibilityService::ISSUE_ALREADY_SETTLED);
        $this->assertNotNull($issue);
        $this->assertSame([$confirmedPeriod->id], $issue['context']['salary_period_ids']);
        $this->assertSame('salary_period', $issue['correction']['action']);
        $this->assertContains(
            SalaryEligibilityService::ISSUE_ALREADY_SETTLED,
            array_column($result['vehicle_results'][$paidVehicle->id]['issues'], 'code'),
        );
        $this->assertTrue($result['vehicle_results'][$currentVehicle->id]['eligible']);
    }

    public function test_draft_period_reference_does_not_block_and_zero_or_negative_profit_vehicles_remain_eligible(): void
    {
        $zeroProfit = $this->validVehicle(['purchase_price' => 100000, 'sold_price' => 100000]);
        $lossVehicle = $this->validVehicle(['purchase_price' => 110000, 'sold_price' => 100000]);
        $draftPeriod = $this->period('2026-05-01', SalaryPeriod::STATUS_DRAFT);
        $this->referenceVehicle($draftPeriod, $zeroProfit);

        $result = $this->service->inspectPeriod('2026-06');

        $this->assertSame([$zeroProfit->id, $lossVehicle->id], $result['eligible_vehicles']->pluck('id')->sort()->values()->all());
        $this->assertSame(0, $result['vehicle_results'][$zeroProfit->id]['gross_profit']);
        $this->assertSame(-10000, $result['vehicle_results'][$lossVehicle->id]['gross_profit']);
        $this->assertFalse($result['has_blocking_issues']);
    }

    public function test_period_selection_uses_taipei_inclusive_start_and_exclusive_next_month_boundary(): void
    {
        $before = $this->validVehicle(['sold_at' => '2026-05-31 23:59:59']);
        $firstSecond = $this->validVehicle(['sold_at' => '2026-06-01 00:00:00']);
        $lastSecond = $this->validVehicle(['sold_at' => '2026-06-30 23:59:59']);
        $nextMonth = $this->validVehicle(['sold_at' => '2026-07-01 00:00:00']);

        $result = $this->service->inspectPeriod('2026-06');

        $this->assertSame(
            [$firstSecond->id, $lastSecond->id],
            $result['eligible_vehicles']->pluck('id')->sort()->values()->all(),
        );
        $this->assertArrayNotHasKey($before->id, $result['vehicle_results']);
        $this->assertArrayNotHasKey($nextMonth->id, $result['vehicle_results']);
    }

    public function test_confirmation_entry_reselects_entire_period(): void
    {
        $existing = $this->validVehicle();
        $draftInspection = $this->service->inspectPeriod('2026-06');
        $this->assertSame([$existing->id], $draftInspection['eligible_vehicles']->pluck('id')->all());

        $lateVehicle = $this->validVehicle();
        $lateVehicle->forceFill(['sales_agent_id' => null])->save();

        try {
            DB::transaction(fn () => $this->service->assertPeriodEligible('2026-06'));
            $this->fail('確認入口必須重新選取並阻擋草稿後新增的異常成交車');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($lateVehicle->stock_no, $exception->errors()['salary_eligibility'][0]);
        }
    }

    public function test_draft_can_keep_anomalies_but_confirmation_guard_rejects_them_with_business_readable_vehicle_number(): void
    {
        $vehicle = $this->validVehicle();
        $vehicle->forceFill(['sales_agent_id' => null])->save();

        $draftInspection = $this->service->inspectPeriod('2026-06');
        $this->assertTrue($draftInspection['has_blocking_issues']);

        try {
            DB::transaction(fn () => $this->service->assertPeriodEligible('2026-06'));
            $this->fail('確認前應阻擋仍有異常的車輛');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($vehicle->stock_no, $exception->errors()['salary_eligibility'][0]);
            $this->assertStringContainsString('尚未指定賣車人', $exception->errors()['salary_eligibility'][0]);
        }
    }

    /** @param array<string, mixed> $overrides */
    private function validVehicle(
        array $overrides = [],
        bool $createCollection = true,
        bool $createPurchasePayment = true,
    ): Vehicle {
        $vehicle = Vehicle::factory()->create(array_merge([
            'status' => 'sold',
            'sold_at' => '2026-06-15 12:00:00',
            'purchase_price' => 60000,
            'sold_price' => 100000,
            'purchase_agent_id' => $this->agent->id,
            'sales_agent_id' => $this->agent->id,
        ], $overrides));

        if ($createCollection) {
            $this->entry($vehicle, 'income', '訂金收入', (int) $vehicle->sold_price);
        }
        if ($createPurchasePayment) {
            $this->entry($vehicle, 'expense', '購車付款', (int) $vehicle->purchase_price);
        }

        return $vehicle;
    }

    private function entry(
        Vehicle $vehicle,
        string $direction,
        string $category,
        int $amount,
        string $approvalStatus = MoneyEntry::APPROVAL_APPROVED,
        string $sourceType = MoneyEntry::SOURCE_MANUAL,
    ): MoneyEntry {
        return MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'direction' => $direction,
            'category' => $category,
            'amount' => $amount,
            'approval_status' => $approvalStatus,
            'source_type' => $sourceType,
        ]);
    }

    private function period(string $month, string $status): SalaryPeriod
    {
        $admin = User::factory()->admin()->create();
        $plan = CommissionPlan::query()->create([
            'name' => "資格測試方案 {$month} {$status}",
            'effective_from' => '2026-01-01',
            'company_reserve_bps' => 4000,
            'purchase_bonus_bps' => 2000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $plan->tiers()->create([
            'min_sales_count' => 1,
            'sales_bonus_bps' => 2000,
            'sort_order' => 1,
        ]);

        return SalaryPeriod::query()->create([
            'period_month' => $month,
            'commission_plan_id' => $plan->id,
            'status' => $status,
            'created_by' => $admin->id,
        ]);
    }

    private function referenceVehicle(SalaryPeriod $period, Vehicle $vehicle): void
    {
        $settlement = SalarySettlement::query()->create([
            'salary_period_id' => $period->id,
            'user_id' => $this->agent->id,
        ]);
        SalarySettlementItem::query()->create([
            'salary_settlement_id' => $settlement->id,
            'type' => SalarySettlementItem::TYPE_SALES_BONUS,
            'vehicle_id' => $vehicle->id,
            'amount' => 0,
            'description' => '資格測試引用',
        ]);
    }
}
