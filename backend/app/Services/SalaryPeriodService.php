<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CommissionPlan;
use App\Models\SalaryPeriod;
use App\Models\SalaryProfile;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\User;
use App\Support\SalaryPeriodMonth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OverflowException;

final class SalaryPeriodService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly CommissionPlanService $commissionPlanService,
        private readonly SalaryEligibilityService $eligibilityService,
        private readonly SalaryCommissionCalculator $calculator,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function createDraft(User $actor, string $periodMonth): SalaryPeriod
    {
        $this->assertAdmin($actor);
        $periodMonth = SalaryPeriodMonth::normalize($periodMonth);

        try {
            return DB::transaction(function () use ($actor, $periodMonth) {
                $plan = $this->commissionPlanService->findEffectiveForMonth($periodMonth);
                if (! $plan) {
                    throw ValidationException::withMessages([
                        'period_month' => ['此月份沒有可用的薪資獎金方案'],
                    ]);
                }

                $period = SalaryPeriod::query()->create([
                    'period_month' => SalaryPeriodMonth::firstDay($periodMonth),
                    'commission_plan_id' => $plan->id,
                    'status' => SalaryPeriod::STATUS_DRAFT,
                    'created_by' => $actor->id,
                ]);

                $eligibility = $this->eligibilityService->inspectPeriodForUpdate($periodMonth, $period->id);
                $this->rebuildDraft($period, $plan, $eligibility);
                $this->auditLogService->recordSalaryPeriodAction($period, AuditLog::ACTION_CREATED, 'create_draft', $actor);

                return $this->loadPeriod($period);
            }, self::TRANSACTION_ATTEMPTS);
        } catch (QueryException $exception) {
            if (! $this->isPeriodMonthUniqueViolation($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'period_month' => ['此月份的薪資草稿已存在'],
            ]);
        }
    }

    public function recalculateDraft(User $actor, SalaryPeriod $period): SalaryPeriod
    {
        $this->assertAdmin($actor);

        return DB::transaction(function () use ($actor, $period) {
            $lockedPeriod = $this->lockDraft($period);
            $plan = CommissionPlan::query()->with('tiers')->findOrFail($lockedPeriod->commission_plan_id);
            $periodMonth = $lockedPeriod->period_month->format('Y-m');
            $eligibility = $this->eligibilityService->inspectPeriodForUpdate($periodMonth, $lockedPeriod->id);
            $this->rebuildDraft($lockedPeriod, $plan, $eligibility);
            $this->auditLogService->recordSalaryPeriodAction($lockedPeriod, AuditLog::ACTION_UPDATED, 'recalculate', $actor);

            return $this->loadPeriod($lockedPeriod);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /** @param array{type: string, amount: int, description: string} $data */
    public function addAdjustment(User $actor, SalarySettlement $settlement, array $data): SalarySettlementItem
    {
        $this->assertAdmin($actor);
        $this->assertAdjustmentData($data);

        return DB::transaction(function () use ($actor, $settlement, $data) {
            $periodId = SalarySettlement::query()->whereKey($settlement->id)->value('salary_period_id');
            $period = SalaryPeriod::query()->whereKey($periodId)->lockForUpdate()->firstOrFail();
            $this->assertDraft($period);
            $lockedSettlement = SalarySettlement::query()
                ->whereKey($settlement->id)
                ->where('salary_period_id', $period->id)
                ->lockForUpdate()
                ->firstOrFail();

            $item = $lockedSettlement->items()->create([
                'type' => $data['type'],
                'amount' => (int) $data['amount'],
                'description' => trim($data['description']),
                'created_by' => $actor->id,
            ]);
            $this->updateSettlementTotals($lockedSettlement);
            $this->auditLogService->recordSalaryAdjustmentAction($period, $item, AuditLog::ACTION_CREATED, $actor);

            return $item;
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function deleteAdjustment(User $actor, SalarySettlementItem $item): void
    {
        $this->assertAdmin($actor);

        DB::transaction(function () use ($actor, $item) {
            $relation = SalarySettlementItem::query()
                ->join('salary_settlements', 'salary_settlements.id', '=', 'salary_settlement_items.salary_settlement_id')
                ->where('salary_settlement_items.id', $item->id)
                ->first(['salary_settlement_items.salary_settlement_id', 'salary_settlements.salary_period_id']);
            $period = SalaryPeriod::query()->whereKey($relation?->salary_period_id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($period);
            $settlement = SalarySettlement::query()
                ->whereKey($relation->salary_settlement_id)
                ->where('salary_period_id', $period->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedItem = SalarySettlementItem::query()
                ->whereKey($item->id)
                ->where('salary_settlement_id', $settlement->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($lockedItem->type, SalarySettlementItem::MANUAL_TYPES, true)) {
                throw ValidationException::withMessages([
                    'item' => ['自動薪資或獎金項目不得手動刪除'],
                ]);
            }

            $this->auditLogService->recordSalaryAdjustmentAction($period, $lockedItem, AuditLog::ACTION_DELETED, $actor);
            $lockedItem->delete();
            $this->updateSettlementTotals($settlement);
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function confirm(User $actor, SalaryPeriod $period): SalaryPeriod
    {
        $this->assertAdmin($actor);

        return DB::transaction(function () use ($actor, $period) {
            $lockedPeriod = $this->lockDraft($period);
            $before = $this->snapshotSignature($lockedPeriod);
            $plan = CommissionPlan::query()->with('tiers')->findOrFail($lockedPeriod->commission_plan_id);
            $periodMonth = $lockedPeriod->period_month->format('Y-m');
            $eligibility = $this->eligibilityService->assertPeriodEligible($periodMonth, $lockedPeriod->id);
            $this->rebuildDraft($lockedPeriod, $plan, $eligibility);
            $after = $this->snapshotSignature($lockedPeriod);

            if ($before !== $after) {
                throw ValidationException::withMessages([
                    'salary_period' => ['草稿資料已變更，請先重新計算並確認結果後再鎖定'],
                ]);
            }

            $hasNegativeNetPay = $lockedPeriod->settlements()->where('net_pay', '<', 0)->exists();
            if ($hasNegativeNetPay) {
                throw ValidationException::withMessages(['net_pay' => ['實發薪資不得小於 0']]);
            }

            $lockedPeriod->status = SalaryPeriod::STATUS_CONFIRMED;
            $lockedPeriod->confirmed_by = $actor->id;
            $lockedPeriod->confirmed_at = now();
            $lockedPeriod->save();
            $this->auditLogService->recordSalaryPeriodAction($lockedPeriod, AuditLog::ACTION_UPDATED, 'confirm', $actor);

            return $this->loadPeriod($lockedPeriod);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /** @param array<string, mixed> $eligibility */
    private function rebuildDraft(SalaryPeriod $period, CommissionPlan $plan, array $eligibility): void
    {
        $profiles = SalaryProfile::query()
            ->where('is_active', true)
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->orderBy('user_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('user_id');

        $settlements = $period->settlements()->orderBy('user_id')->lockForUpdate()->get()->keyBy('user_id');
        foreach ($profiles as $profile) {
            if (! $settlements->has($profile->user_id)) {
                $settlements->put($profile->user_id, $period->settlements()->create(['user_id' => $profile->user_id]));
            }
        }

        SalarySettlementItem::query()
            ->whereIn('salary_settlement_id', $settlements->pluck('id'))
            ->whereIn('type', SalarySettlementItem::AUTOMATIC_TYPES)
            ->delete();

        $enabledAgentIds = $profiles->filter(fn (SalaryProfile $profile) => $profile->commission_enabled)->keys()->map(fn ($id) => (int) $id)->all();
        $calculation = $this->calculator->calculate(
            $plan,
            $period->period_month->format('Y-m'),
            $eligibility['eligible_vehicles'],
            $enabledAgentIds,
        );

        foreach ($settlements as $userId => $settlement) {
            $profile = $profiles->get($userId);
            $salary = $profile ? (int) $profile->base_salary : 0;
            $allowance = $profile ? (int) $profile->fixed_allowance : 0;
            $labor = $profile ? (int) $profile->labor_insurance_deduction : 0;
            $health = $profile ? (int) $profile->health_insurance_deduction : 0;
            $salesSummary = $calculation['sales_agents'][(int) $userId] ?? null;

            $settlement->forceFill([
                'eligible_sales_count' => (int) ($salesSummary['eligible_sales_count'] ?? 0),
                'sales_bonus_bps_snapshot' => (int) ($salesSummary['sales_bonus_bps'] ?? 0),
                'base_salary_snapshot' => $salary,
                'fixed_allowance_snapshot' => $allowance,
                'labor_insurance_deduction_snapshot' => $labor,
                'health_insurance_deduction_snapshot' => $health,
            ])->save();

            $this->createAutomaticItem($settlement, SalarySettlementItem::TYPE_BASE_SALARY, $salary, '底薪');
            $this->createAutomaticItem($settlement, SalarySettlementItem::TYPE_FIXED_ALLOWANCE, $allowance, '固定津貼');
            $this->createAutomaticItem($settlement, SalarySettlementItem::TYPE_LABOR_INSURANCE, $labor, '勞保扣款');
            $this->createAutomaticItem($settlement, SalarySettlementItem::TYPE_HEALTH_INSURANCE, $health, '健保扣款');
        }

        foreach ($calculation['vehicles'] as $vehicleResult) {
            $snapshot = $this->vehicleCalculationSnapshot($period, $plan, $vehicleResult);
            $purchaseSettlement = $settlements->get($vehicleResult['purchase_agent_id']);
            if ($purchaseSettlement) {
                $this->createAutomaticItem(
                    $purchaseSettlement,
                    SalarySettlementItem::TYPE_PURCHASE_BONUS,
                    (int) $vehicleResult['purchase_bonus'],
                    '車輛收車獎金',
                    (int) $vehicleResult['vehicle_id'],
                    $snapshot,
                );
            }
            $salesSettlement = $settlements->get($vehicleResult['sales_agent_id']);
            if ($salesSettlement) {
                $this->createAutomaticItem(
                    $salesSettlement,
                    SalarySettlementItem::TYPE_SALES_BONUS,
                    (int) $vehicleResult['sales_bonus'],
                    '車輛賣車獎金',
                    (int) $vehicleResult['vehicle_id'],
                    $snapshot,
                );
            }
        }

        foreach ($settlements as $settlement) {
            $this->updateSettlementTotals($settlement);
        }
    }

    private function createAutomaticItem(
        SalarySettlement $settlement,
        string $type,
        int $amount,
        string $description,
        ?int $vehicleId = null,
        ?array $snapshot = null,
    ): void {
        $settlement->items()->create([
            'type' => $type,
            'vehicle_id' => $vehicleId,
            'amount' => $amount,
            'description' => $description,
            'calculation_snapshot' => $snapshot,
        ]);
    }

    /** @param array<string, int|bool> $result */
    private function vehicleCalculationSnapshot(SalaryPeriod $period, CommissionPlan $plan, array $result): array
    {
        return [
            'period_month' => $period->period_month->format('Y-m'),
            'income_total' => $result['income_total'],
            'expense_total' => $result['expense_total'],
            'gross_profit' => $result['gross_profit'],
            'company_reserve_bps' => (int) $plan->company_reserve_bps,
            'company_reserve' => $result['company_reserve'],
            'distributable_pool' => $result['distributable_pool'],
            'purchase_bonus_bps' => $result['purchase_bonus_bps'],
            'purchase_bonus' => $result['purchase_bonus'],
            'sales_bonus_bps' => $result['sales_bonus_bps'],
            'sales_bonus' => $result['sales_bonus'],
            'company_remaining' => $result['company_remaining'],
            'purchase_agent_id' => $result['purchase_agent_id'],
            'sales_agent_id' => $result['sales_agent_id'],
        ];
    }

    private function updateSettlementTotals(SalarySettlement $settlement): void
    {
        $totals = $settlement->items()
            ->select('type')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($amount) => $this->validatedNonNegativeInteger($amount))
            ->all();

        $purchase = $totals[SalarySettlementItem::TYPE_PURCHASE_BONUS] ?? 0;
        $sales = $totals[SalarySettlementItem::TYPE_SALES_BONUS] ?? 0;
        $manualAddition = $totals[SalarySettlementItem::TYPE_MANUAL_ADDITION] ?? 0;
        $manualDeduction = $totals[SalarySettlementItem::TYPE_MANUAL_DEDUCTION] ?? 0;
        $gross = $this->safeSum([
            $totals[SalarySettlementItem::TYPE_BASE_SALARY] ?? 0,
            $totals[SalarySettlementItem::TYPE_FIXED_ALLOWANCE] ?? 0,
            $purchase,
            $sales,
            $manualAddition,
        ]);
        $deductions = $this->safeSum([
            $totals[SalarySettlementItem::TYPE_LABOR_INSURANCE] ?? 0,
            $totals[SalarySettlementItem::TYPE_HEALTH_INSURANCE] ?? 0,
            $manualDeduction,
        ]);

        if ($deductions > $gross) {
            throw ValidationException::withMessages(['amount' => ['扣款合計不得超過應發薪資']]);
        }

        $settlement->forceFill([
            'purchase_bonus_total' => $purchase,
            'sales_bonus_total' => $sales,
            'manual_addition_total' => $manualAddition,
            'manual_deduction_total' => $manualDeduction,
            'gross_pay' => $gross,
            'deduction_total' => $deductions,
            'net_pay' => $gross - $deductions,
        ])->save();
    }

    private function snapshotSignature(SalaryPeriod $period): string
    {
        $rows = $period->settlements()->with(['items' => fn ($query) => $query->orderBy('type')->orderBy('vehicle_id')->orderBy('id')])
            ->orderBy('user_id')->get()->map(fn (SalarySettlement $settlement) => [
                'user_id' => (int) $settlement->user_id,
                'values' => collect($settlement->getAttributes())->only([
                    'eligible_sales_count', 'sales_bonus_bps_snapshot', 'base_salary_snapshot',
                    'fixed_allowance_snapshot', 'labor_insurance_deduction_snapshot',
                    'health_insurance_deduction_snapshot', 'purchase_bonus_total', 'sales_bonus_total',
                    'manual_addition_total', 'manual_deduction_total', 'gross_pay', 'deduction_total', 'net_pay',
                ])->all(),
                'items' => $settlement->items->map(fn (SalarySettlementItem $item) => [
                    'type' => $item->type,
                    'vehicle_id' => $item->vehicle_id === null ? null : (int) $item->vehicle_id,
                    'amount' => (int) $item->amount,
                    'description' => $item->description,
                    'calculation_snapshot' => $item->calculation_snapshot,
                    'created_by' => $item->created_by === null ? null : (int) $item->created_by,
                ])->all(),
            ])->all();

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function loadPeriod(SalaryPeriod $period): SalaryPeriod
    {
        return $period->load([
            'plan.tiers',
            'settlements' => fn ($query) => $query->orderBy('user_id'),
            'settlements.user:id,name,email,role',
            'settlements.items' => fn ($query) => $query->orderBy('type')->orderBy('vehicle_id')->orderBy('id'),
        ]);
    }

    private function lockDraft(SalaryPeriod $period): SalaryPeriod
    {
        $locked = SalaryPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
        $this->assertDraft($locked);

        return $locked;
    }

    private function assertDraft(SalaryPeriod $period): void
    {
        if ($period->status !== SalaryPeriod::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => ['只有草稿薪資月份可以執行此操作']]);
        }
    }

    private function assertAdmin(User $actor): void
    {
        if (! $actor->isAdmin() || ! $actor->is_active) {
            throw new AuthorizationException('只有啟用中的管理員可以操作薪資結算');
        }
    }

    /** @param array<string, mixed> $data */
    private function assertAdjustmentData(array $data): void
    {
        if (! isset($data['type']) || ! in_array($data['type'], SalarySettlementItem::MANUAL_TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['手動項目只允許其他加給或其他扣款']]);
        }
        if (! isset($data['amount']) || ! is_int($data['amount']) || $data['amount'] <= 0) {
            throw ValidationException::withMessages(['amount' => ['金額必須是正整數']]);
        }
        if (! isset($data['description']) || ! is_string($data['description']) || trim($data['description']) === '') {
            throw ValidationException::withMessages(['description' => ['說明為必填']]);
        }
    }

    private function validatedNonNegativeInteger(mixed $value): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false || $validated < 0) {
            throw new OverflowException('薪資合計超出可安全計算的整數範圍');
        }

        return $validated;
    }

    /** @param int[] $values */
    private function safeSum(array $values): int
    {
        $total = 0;
        foreach ($values as $value) {
            if ($value > PHP_INT_MAX - $total) {
                throw new OverflowException('薪資合計超出可安全計算的整數範圍');
            }
            $total += $value;
        }

        return $total;
    }

    private function isPeriodMonthUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && str_contains($exception->getMessage(), 'salary_periods')
            && str_contains($exception->getMessage(), 'period_month');
    }
}
