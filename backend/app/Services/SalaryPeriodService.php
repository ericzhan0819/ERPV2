<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CommissionPlan;
use App\Models\MoneyEntry;
use App\Models\SalaryPeriod;
use App\Models\SalaryProfile;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\User;
use App\Support\SalaryPeriodMonth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
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
        $this->assertPeriodMonthNotFuture($periodMonth);

        try {
            return DB::transaction(function () use ($actor, $periodMonth) {
                $plan = $this->commissionPlanService->findEffectiveForMonthForUpdate($periodMonth);
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
                $plan->load('tiers');
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
            $periodMonth = $lockedPeriod->period_month->format('Y-m');
            $eligibility = $this->eligibilityService->inspectPeriodForUpdate($periodMonth, $lockedPeriod->id);
            $plan = CommissionPlan::query()->with('tiers')->findOrFail($lockedPeriod->commission_plan_id);
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
            $netPayBeforeAdjustment = (int) $lockedSettlement->net_pay;

            $item = $lockedSettlement->items()->create([
                'type' => $data['type'],
                'amount' => (int) $data['amount'],
                'description' => trim($data['description']),
                'created_by' => $actor->id,
            ]);
            $this->updateSettlementTotals($lockedSettlement);
            if ($data['type'] === SalarySettlementItem::TYPE_MANUAL_DEDUCTION
                && $lockedSettlement->net_pay < 0) {
                $message = $netPayBeforeAdjustment < 0
                    ? '目前實發薪資已小於 0，請先用其他加給補平或調整既有扣款'
                    : '此扣款會使實發薪資小於 0，請先調整其他薪資項目';
                throw ValidationException::withMessages([
                    'amount' => [$message],
                ]);
            }
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
            $periodMonth = $lockedPeriod->period_month->format('Y-m');
            $eligibility = $this->eligibilityService->assertPeriodEligible($periodMonth, $lockedPeriod->id);
            $before = $this->snapshotSignature($lockedPeriod);
            $plan = CommissionPlan::query()->with('tiers')->findOrFail($lockedPeriod->commission_plan_id);
            $this->rebuildDraft($lockedPeriod, $plan, $eligibility);
            $after = $this->snapshotSignature($lockedPeriod);

            if ($before !== $after) {
                throw ValidationException::withMessages([
                    'salary_period' => ['草稿資料已變更，請先重新計算並確認結果後再鎖定'],
                ]);
            }

            $negativeSettlements = $lockedPeriod->settlements()
                ->with('user:id,name')
                ->where('net_pay', '<', 0)
                ->orderBy('user_id')
                ->get();
            if ($negativeSettlements->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'net_pay' => $negativeSettlements
                        ->map(fn (SalarySettlement $settlement): string => "{$settlement->user->name}：實發薪資為 {$settlement->net_pay}，不得小於 0")
                        ->all(),
                ]);
            }

            $lockedPeriod->status = SalaryPeriod::STATUS_CONFIRMED;
            $lockedPeriod->confirmed_by = $actor->id;
            $lockedPeriod->confirmed_at = now();
            $lockedPeriod->save();
            $this->auditLogService->recordSalaryPeriodAction($lockedPeriod, AuditLog::ACTION_UPDATED, 'confirm', $actor);

            return $this->loadPeriod($lockedPeriod);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * @param  array{cash_account_id: int|string, payment_date: string, idempotency_key: string}  $data
     */
    public function pay(User $actor, SalaryPeriod $period, array $data): SalaryPeriod
    {
        $this->assertAdmin($actor);
        $payload = $this->normalizePaymentData($data);

        try {
            return DB::transaction(function () use ($actor, $period, $payload) {
                $lockedPeriod = SalaryPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

                if ($lockedPeriod->status === SalaryPeriod::STATUS_PAID) {
                    return $this->replayOrRejectPayment($lockedPeriod, $payload);
                }
                if ($lockedPeriod->status !== SalaryPeriod::STATUS_CONFIRMED) {
                    throw ValidationException::withMessages([
                        'status' => ['只有已確認的薪資月份可以發薪'],
                    ]);
                }
                $this->assertPaymentDateAllowed($lockedPeriod, $payload['payment_date']);

                $keyOwner = SalaryPeriod::query()
                    ->where('idempotency_key', $payload['idempotency_key'])
                    ->whereKeyNot($lockedPeriod->id)
                    ->first();
                if ($keyOwner) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['此冪等鍵已被其他薪資月份使用，請重新產生後再試'],
                    ]);
                }

                $account = CashAccount::query()->whereKey($payload['cash_account_id'])->lockForUpdate()->first();
                if (! $account || ! $account->is_active) {
                    throw ValidationException::withMessages([
                        'cash_account_id' => ['找不到指定的啟用中資金帳戶'],
                    ]);
                }

                $settlements = SalarySettlement::query()
                    ->with('user:id,name')
                    ->where('salary_period_id', $lockedPeriod->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($settlements as $settlement) {
                    if ($settlement->net_pay < 0) {
                        throw ValidationException::withMessages([
                            'net_pay' => ["{$settlement->user->name} 的實發薪資不得小於 0"],
                        ]);
                    }
                    if ($settlement->money_entry_id !== null) {
                        throw ValidationException::withMessages([
                            'salary_period' => ['此薪資月份已有員工薪資支出，請勿重複發薪'],
                        ]);
                    }
                    if ($settlement->net_pay === 0) {
                        continue;
                    }

                    $entry = new MoneyEntry([
                        'vehicle_id' => null,
                        'cash_account_id' => $account->id,
                        'entry_date' => $payload['payment_date'],
                        'direction' => 'expense',
                        'category' => '薪資 / 佣金',
                        'amount' => $settlement->net_pay,
                        'counterparty_name' => $settlement->user->name,
                        'description' => $lockedPeriod->period_month->format('Y-m').' 薪資 / 佣金',
                        'idempotency_key' => $this->salaryMoneyEntryKey(
                            $payload['idempotency_key'],
                            $settlement->id,
                        ),
                        'source_type' => MoneyEntry::SOURCE_SALARY_SETTLEMENT,
                    ]);
                    $entry->created_by = $actor->id;
                    $entry->updated_by = $actor->id;
                    $entry->approval_status = MoneyEntry::APPROVAL_APPROVED;
                    $entry->approved_by = $actor->id;
                    $entry->approved_at = now();
                    $entry->save();

                    $settlement->money_entry_id = $entry->id;
                    $settlement->save();
                }

                $lockedPeriod->forceFill([
                    'status' => SalaryPeriod::STATUS_PAID,
                    'cash_account_id' => $account->id,
                    'payment_date' => $payload['payment_date'],
                    'idempotency_key' => $payload['idempotency_key'],
                    'paid_by' => $actor->id,
                    'paid_at' => now(),
                ])->save();
                $this->auditLogService->recordSalaryPeriodAction($lockedPeriod, AuditLog::ACTION_UPDATED, 'pay', $actor);

                return $this->loadPeriod($lockedPeriod);
            }, self::TRANSACTION_ATTEMPTS);
        } catch (QueryException $exception) {
            if (! $this->isSalaryPaymentKeyUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->replayRacedPaymentAfterRollback($exception, $period->id, $payload);
        }
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
            'plan.createdBy:id,name',
            'settlements' => fn ($query) => $query->orderBy('user_id'),
            'settlements.user:id,name,email,role',
            'settlements.items' => fn ($query) => $query->orderBy('type')->orderBy('vehicle_id')->orderBy('id'),
            'settlements.items.vehicle:id,stock_no,brand,model',
            'settlements.items.createdBy:id,name',
            'settlements.moneyEntry',
            'createdBy:id,name',
            'confirmedBy:id,name',
            'paidBy:id,name',
            'cashAccount:id,name,type',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{cash_account_id: int, payment_date: string, idempotency_key: string}
     */
    private function normalizePaymentData(array $data): array
    {
        $rawAccountId = $data['cash_account_id'] ?? null;
        $accountId = is_int($rawAccountId)
            ? $rawAccountId
            : (is_string($rawAccountId) && ctype_digit($rawAccountId) ? (int) $rawAccountId : 0);
        if ($accountId <= 0) {
            throw ValidationException::withMessages(['cash_account_id' => ['資金帳戶必須是正整數']]);
        }

        $paymentDate = $data['payment_date'] ?? null;
        try {
            $parsedDate = is_string($paymentDate) ? Carbon::createFromFormat('!Y-m-d', $paymentDate) : false;
        } catch (\Throwable) {
            $parsedDate = false;
        }
        if (! $parsedDate || $parsedDate->format('Y-m-d') !== $paymentDate) {
            throw ValidationException::withMessages(['payment_date' => ['發薪日期必須是有效的 YYYY-MM-DD 日期']]);
        }

        $key = $data['idempotency_key'] ?? null;
        if (! is_string($key) || trim($key) === '' || strlen($key) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => ['冪等鍵為必填，且長度不可超過 100 字元']]);
        }

        return [
            'cash_account_id' => $accountId,
            'payment_date' => $paymentDate,
            'idempotency_key' => trim($key),
        ];
    }

    /** @param array{cash_account_id: int, payment_date: string, idempotency_key: string} $payload */
    private function replayOrRejectPayment(SalaryPeriod $period, array $payload): SalaryPeriod
    {
        if ($period->idempotency_key !== $payload['idempotency_key']
            || (int) $period->cash_account_id !== $payload['cash_account_id']
            || $period->payment_date?->toDateString() !== $payload['payment_date']) {
            $field = $period->idempotency_key === $payload['idempotency_key'] ? 'idempotency_key' : 'status';
            $message = $field === 'idempotency_key'
                ? '此冪等鍵已用於不同的發薪內容'
                : '此薪資月份已使用其他冪等鍵完成發薪';
            throw ValidationException::withMessages([$field => [$message]]);
        }

        return $this->loadPeriod($period);
    }

    private function assertPaymentDateAllowed(SalaryPeriod $period, string $paymentDate): void
    {
        $date = Carbon::createFromFormat('!Y-m-d', $paymentDate);
        $periodStart = $period->period_month->copy()->startOfDay();
        $today = Carbon::today();

        if ($date->lt($periodStart)) {
            throw ValidationException::withMessages([
                'payment_date' => ['發薪日期不得早於結算月份第一天'],
            ]);
        }
        if ($date->gt($today)) {
            throw ValidationException::withMessages([
                'payment_date' => ['發薪代表款項已實際支付，日期不得晚於今天'],
            ]);
        }
    }

    /**
     * @param  array{cash_account_id: int, payment_date: string, idempotency_key: string}  $payload
     */
    private function replayRacedPaymentAfterRollback(
        QueryException $original,
        int $periodId,
        array $payload,
    ): SalaryPeriod {
        return DB::transaction(function () use ($original, $periodId, $payload) {
            // duplicate-key 發生時原交易已完整回滾；必須以新交易讀取已提交 winner，
            // 不可在 MySQL REPEATABLE READ 的舊快照內假裝 replay。
            $winner = SalaryPeriod::query()
                ->where('idempotency_key', $payload['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if (! $winner) {
                throw $original;
            }
            if ((int) $winner->id !== $periodId) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['此冪等鍵已被其他薪資月份使用，請重新產生後再試'],
                ]);
            }

            return $this->replayOrRejectPayment($winner, $payload);
        });
    }

    private function isSalaryPaymentKeyUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && str_contains($exception->getMessage(), 'salary_periods')
            && str_contains($exception->getMessage(), 'idempotency_key');
    }

    private function salaryMoneyEntryKey(string $batchKey, int $settlementId): string
    {
        // 不直接使用可預測的 settlement id，避免一般收支先占用固定 key 而阻斷發薪。
        return 'salary-payment:'.hash('sha256', $batchKey.':'.$settlementId);
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

    private function assertPeriodMonthNotFuture(string $periodMonth): void
    {
        $currentMonth = Carbon::now(config('app.timezone'))->format('Y-m');
        if ($periodMonth > $currentMonth) {
            throw ValidationException::withMessages([
                'period_month' => ["結算月份不得晚於台北目前月份（{$currentMonth}）"],
            ]);
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
