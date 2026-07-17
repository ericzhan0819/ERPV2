<?php

namespace App\Services;

use App\Models\MoneyEntry;
use App\Models\SalaryPeriod;
use App\Models\SalarySettlementItem;
use App\Models\Vehicle;
use App\Support\SalaryPeriodMonth;
use App\Support\VehicleMoneyCategories;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use OverflowException;

final class SalaryEligibilityService
{
    public const ISSUE_STATUS_NOT_SOLD = 'status_not_sold';

    public const ISSUE_SOLD_AT_OUTSIDE_PERIOD = 'sold_at_outside_period';

    public const ISSUE_PURCHASE_AGENT_MISSING = 'purchase_agent_missing';

    public const ISSUE_SALES_AGENT_MISSING = 'sales_agent_missing';

    public const ISSUE_PENDING_MONEY_ENTRY = 'pending_money_entry';

    public const ISSUE_COLLECTION_SHORTFALL = 'approved_collection_shortfall';

    public const ISSUE_PURCHASE_PAYMENT_MISMATCH = 'approved_purchase_payment_mismatch';

    public const ISSUE_LEGACY_UNKNOWN_MONEY_ENTRY = 'legacy_unknown_money_entry';

    public const ISSUE_ALREADY_SETTLED = 'already_in_confirmed_or_paid_period';

    private const PURCHASE_PAYMENT_CATEGORY = '購車付款';

    /**
     * 選出指定月份的正式成交車並逐台檢查。非 sold 或其他月份不是本月候選車，
     * 因此不會出現在結果；本月候選車則一律保留，異常不得被靜默略過。
     *
     * @return array<string, mixed>
     */
    public function inspectPeriod(string $periodMonth, ?int $currentSalaryPeriodId = null): array
    {
        return $this->inspectPeriodFromDatabase($periodMonth, $currentSalaryPeriodId, false);
    }

    /**
     * 薪資月份確認唯一允許的資格入口：必須在既有 transaction 內重新從資料庫選出
     * 整個月份候選車並鎖定車輛列，不能接受呼叫端提供的舊草稿集合。
     *
     * @return array<string, mixed>
     */
    public function assertPeriodEligible(string $periodMonth, ?int $currentSalaryPeriodId = null): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('確認薪資資格必須在資料庫 transaction 內執行');
        }

        $result = $this->inspectPeriodFromDatabase($periodMonth, $currentSalaryPeriodId, true);
        $this->assertNoBlockingIssues($result);

        return $result;
    }

    /**
     * 草稿建立／重算可保留異常，但仍必須與歸屬修改及收支核准共用車輛列鎖。
     *
     * @return array<string, mixed>
     */
    public function inspectPeriodForUpdate(string $periodMonth, ?int $currentSalaryPeriodId = null): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('鎖定薪資資格資料必須在資料庫 transaction 內執行');
        }

        return $this->inspectPeriodFromDatabase($periodMonth, $currentSalaryPeriodId, true);
    }

    /** @return array<string, mixed> */
    private function inspectPeriodFromDatabase(
        string $periodMonth,
        ?int $currentSalaryPeriodId,
        bool $lockVehicles,
    ): array {
        $periodMonth = SalaryPeriodMonth::normalize($periodMonth);
        [$monthStart, $nextMonthStart] = $this->monthBounds($periodMonth);

        $query = Vehicle::query()
            ->where('status', 'sold')
            ->where('sold_at', '>=', $monthStart)
            ->where('sold_at', '<', $nextMonthStart)
            ->orderBy('sold_at')
            ->orderBy('id');

        if ($lockVehicles) {
            $query->lockForUpdate();
        }

        $vehicles = $query->get();

        return $this->inspectVehicles($periodMonth, $vehicles, $currentSalaryPeriodId);
    }

    /**
     * 對呼叫端已鎖定或已選取的候選集合做完整防禦性檢查。
     *
     * @param  iterable<int, Vehicle>  $vehicles
     * @return array{
     *     period_month: string,
     *     eligible_vehicles: Collection<int, Vehicle>,
     *     vehicle_results: array<int, array<string, mixed>>,
     *     anomalies: array<int, array<string, mixed>>,
     *     has_blocking_issues: bool
     * }
     */
    private function inspectVehicles(
        string $periodMonth,
        iterable $vehicles,
        ?int $currentSalaryPeriodId = null,
    ): array {
        $periodMonth = SalaryPeriodMonth::normalize($periodMonth);
        if ($currentSalaryPeriodId !== null && $currentSalaryPeriodId <= 0) {
            throw new InvalidArgumentException('目前薪資月份 ID 必須是正整數');
        }
        if ($currentSalaryPeriodId !== null) {
            $currentPeriodMonth = SalaryPeriod::query()->whereKey($currentSalaryPeriodId)->value('period_month');
            if ($currentPeriodMonth === null || CarbonImmutable::parse($currentPeriodMonth)->format('Y-m') !== $periodMonth) {
                throw new InvalidArgumentException('目前薪資月份與資格檢查月份不一致');
            }
        }

        $vehicles = collect($vehicles)->values()->sortBy('id')->values();
        $this->assertValidVehicleSet($vehicles);

        $vehicleIds = $vehicles->map(fn (Vehicle $vehicle): int => (int) $vehicle->getKey())->all();
        $moneyFacts = $this->moneyFacts($vehicleIds);
        $settledPeriodIds = $this->settledPeriodIds($vehicleIds, $currentSalaryPeriodId);
        $eligibleVehicles = collect();
        $vehicleResults = [];
        $anomalies = [];

        foreach ($vehicles as $vehicle) {
            $vehicleId = (int) $vehicle->getKey();
            $facts = $moneyFacts[$vehicleId] ?? $this->emptyMoneyFacts();
            $issues = $this->issuesForVehicle(
                $vehicle,
                $periodMonth,
                $facts,
                $settledPeriodIds[$vehicleId] ?? [],
            );
            $eligible = $issues === [];

            if ($eligible) {
                $eligibleVehicles->push($vehicle);
            } else {
                array_push($anomalies, ...$issues);
            }

            $vehicleResults[$vehicleId] = [
                'vehicle' => $vehicle,
                'vehicle_id' => $vehicleId,
                'stock_no' => (string) $vehicle->stock_no,
                'eligible' => $eligible,
                'gross_profit' => $this->safeSubtract($facts['approved_income_total'], $facts['approved_expense_total']),
                'issues' => $issues,
            ];
        }

        return [
            'period_month' => $periodMonth,
            'eligible_vehicles' => $eligibleVehicles->values(),
            'vehicle_results' => $vehicleResults,
            'anomalies' => $anomalies,
            'has_blocking_issues' => $anomalies !== [],
        ];
    }

    /** @param array<string, mixed> $result */
    private function assertNoBlockingIssues(array $result): void
    {
        if ($result['has_blocking_issues']) {
            throw ValidationException::withMessages([
                'salary_eligibility' => array_map(
                    fn (array $issue): string => "{$issue['stock_no']}：{$issue['message']}",
                    $result['anomalies'],
                ),
            ]);
        }
    }

    /**
     * @param  array<string, int|bool>  $facts
     * @param  array<int, int>  $settledPeriodIds
     * @return array<int, array<string, mixed>>
     */
    private function issuesForVehicle(
        Vehicle $vehicle,
        string $periodMonth,
        array $facts,
        array $settledPeriodIds,
    ): array {
        $issues = [];

        if ($vehicle->status !== 'sold') {
            $issues[] = $this->issue($vehicle, self::ISSUE_STATUS_NOT_SOLD, 'status', '車輛尚未成交結案', '查看車輛狀態', 'vehicle_detail');
        }

        if ($vehicle->sold_at === null || $vehicle->sold_at->format('Y-m') !== $periodMonth) {
            $issues[] = $this->issue($vehicle, self::ISSUE_SOLD_AT_OUTSIDE_PERIOD, 'sold_at', "成交日期不屬於 {$periodMonth}", '查看成交日期', 'vehicle_detail');
        }

        if ($vehicle->purchase_agent_id === null) {
            $issues[] = $this->issue($vehicle, self::ISSUE_PURCHASE_AGENT_MISSING, 'purchase_agent_id', '尚未指定收車人', '補登獎金歸屬', 'commission_attribution');
        }

        if ($vehicle->sales_agent_id === null) {
            $issues[] = $this->issue($vehicle, self::ISSUE_SALES_AGENT_MISSING, 'sales_agent_id', '尚未指定賣車人', '補登獎金歸屬', 'commission_attribution');
        }

        if ($facts['has_pending']) {
            $issues[] = $this->issue($vehicle, self::ISSUE_PENDING_MONEY_ENTRY, 'money_entries', '仍有待審核收支，請先核准或駁回', '查看車輛收支', 'vehicle_money_entries');
        }

        $soldPrice = $vehicle->sold_price;
        $approvedNetCollection = $this->safeSubtract(
            $facts['approved_collection_total'],
            $facts['approved_refund_total'],
        );
        if ($soldPrice === null || $approvedNetCollection < (int) $soldPrice) {
            $message = $soldPrice === null
                ? '尚未設定成交價，無法驗證已核准收款'
                : "已核准訂金／尾款扣退款為 {$approvedNetCollection}，未達成交價 {$soldPrice}";
            $issues[] = $this->issue($vehicle, self::ISSUE_COLLECTION_SHORTFALL, 'sold_price', $message, '查看銷售收款', 'vehicle_money_entries');
        }

        if ($vehicle->purchase_price === null || $facts['approved_purchase_payment_total'] !== (int) $vehicle->purchase_price) {
            $expected = $vehicle->purchase_price === null ? '未設定' : (string) $vehicle->purchase_price;
            $issues[] = $this->issue(
                $vehicle,
                self::ISSUE_PURCHASE_PAYMENT_MISMATCH,
                'purchase_price',
                "已核准購車付款為 {$facts['approved_purchase_payment_total']}，收購價為 {$expected}",
                $vehicle->purchase_price === null ? '補登收購價' : '查看購車付款',
                $vehicle->purchase_price === null ? 'vehicle_purchase_price' : 'vehicle_money_entries',
            );
        }

        if ($facts['has_legacy_unknown']) {
            $issues[] = $this->issue($vehicle, self::ISSUE_LEGACY_UNKNOWN_MONEY_ENTRY, 'money_entries', '存在來源未確認的既有收支，需先完成人工確認', '確認收支來源', 'money_entry_source_review');
        }

        if ($settledPeriodIds !== []) {
            $periodId = $settledPeriodIds[0];
            $issues[] = $this->issue(
                $vehicle,
                self::ISSUE_ALREADY_SETTLED,
                'salary_period_id',
                '已被其他已確認或已發薪的薪資月份引用',
                '查看既有薪資月份',
                'salary_period',
                ['salary_period_ids' => $settledPeriodIds],
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function issue(
        Vehicle $vehicle,
        string $code,
        string $field,
        string $message,
        string $correctionLabel,
        string $correctionAction,
        array $context = [],
    ): array {
        return [
            'vehicle_id' => (int) $vehicle->getKey(),
            'stock_no' => (string) $vehicle->stock_no,
            'code' => $code,
            'field' => $field,
            'message' => $message,
            'correction' => [
                'label' => $correctionLabel,
                'action' => $correctionAction,
            ],
            'context' => $context,
        ];
    }

    /**
     * @param  array<int, int>  $vehicleIds
     * @return array<int, array<string, int|bool>>
     */
    private function moneyFacts(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $facts = [];
        MoneyEntry::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->select(['vehicle_id', 'direction', 'category', 'amount', 'approval_status', 'source_type'])
            ->orderBy('vehicle_id')
            ->orderBy('id')
            ->each(function (MoneyEntry $entry) use (&$facts): void {
                $vehicleId = (int) $entry->vehicle_id;
                $facts[$vehicleId] ??= $this->emptyMoneyFacts();

                if ($entry->approval_status === MoneyEntry::APPROVAL_PENDING) {
                    $facts[$vehicleId]['has_pending'] = true;
                }
                if ($entry->source_type === MoneyEntry::SOURCE_LEGACY_UNKNOWN) {
                    $facts[$vehicleId]['has_legacy_unknown'] = true;
                }
                if ($entry->approval_status !== MoneyEntry::APPROVAL_APPROVED) {
                    return;
                }

                $amount = (int) $entry->amount;
                $totalField = $entry->direction === 'income'
                    ? 'approved_income_total'
                    : 'approved_expense_total';
                $facts[$vehicleId][$totalField] = $this->safeAdd($facts[$vehicleId][$totalField], $amount);

                if ($entry->direction === 'income' && in_array($entry->category, VehicleMoneyCategories::SALES_COLLECTION_INCOME, true)) {
                    $facts[$vehicleId]['approved_collection_total'] = $this->safeAdd($facts[$vehicleId]['approved_collection_total'], $amount);
                } elseif ($entry->direction === 'expense' && $entry->category === VehicleMoneyCategories::SALES_REFUND) {
                    $facts[$vehicleId]['approved_refund_total'] = $this->safeAdd($facts[$vehicleId]['approved_refund_total'], $amount);
                } elseif ($entry->direction === 'expense' && $entry->category === self::PURCHASE_PAYMENT_CATEGORY) {
                    $facts[$vehicleId]['approved_purchase_payment_total'] = $this->safeAdd($facts[$vehicleId]['approved_purchase_payment_total'], $amount);
                }
            });

        return $facts;
    }

    /**
     * @param  array<int, int>  $vehicleIds
     * @return array<int, array<int, int>>
     */
    private function settledPeriodIds(array $vehicleIds, ?int $currentSalaryPeriodId): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $rows = SalarySettlementItem::query()
            ->join('salary_settlements', 'salary_settlements.id', '=', 'salary_settlement_items.salary_settlement_id')
            ->join('salary_periods', 'salary_periods.id', '=', 'salary_settlements.salary_period_id')
            ->whereIn('salary_settlement_items.vehicle_id', $vehicleIds)
            ->whereIn('salary_periods.status', [SalaryPeriod::STATUS_CONFIRMED, SalaryPeriod::STATUS_PAID])
            ->when($currentSalaryPeriodId !== null, fn ($query) => $query->where('salary_periods.id', '!=', $currentSalaryPeriodId))
            ->select(['salary_settlement_items.vehicle_id', 'salary_periods.id as salary_period_id'])
            ->distinct()
            ->orderBy('salary_settlement_items.vehicle_id')
            ->orderBy('salary_periods.id')
            ->get();

        $periodIds = [];
        foreach ($rows as $row) {
            $periodIds[(int) $row->vehicle_id][] = (int) $row->salary_period_id;
        }

        return $periodIds;
    }

    /** @param Collection<int, mixed> $vehicles */
    private function assertValidVehicleSet(Collection $vehicles): void
    {
        $ids = [];
        foreach ($vehicles as $vehicle) {
            if (! $vehicle instanceof Vehicle || ! $vehicle->exists || $vehicle->getKey() === null) {
                throw new InvalidArgumentException('薪資資格檢查只接受已儲存的 Vehicle');
            }
            if (isset($ids[$vehicle->getKey()])) {
                throw new InvalidArgumentException("車輛 {$vehicle->getKey()} 不可重複檢查");
            }
            $ids[$vehicle->getKey()] = true;
        }
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function monthBounds(string $periodMonth): array
    {
        $start = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            SalaryPeriodMonth::firstDay($periodMonth),
            config('app.timezone'),
        )->startOfDay();

        return [$start, $start->addMonth()];
    }

    /** @return array<string, int|bool> */
    private function emptyMoneyFacts(): array
    {
        return [
            'approved_income_total' => 0,
            'approved_expense_total' => 0,
            'approved_collection_total' => 0,
            'approved_refund_total' => 0,
            'approved_purchase_payment_total' => 0,
            'has_pending' => false,
            'has_legacy_unknown' => false,
        ];
    }

    private function safeAdd(int $left, int $right): int
    {
        if ($right < 0 || $left > PHP_INT_MAX - $right) {
            throw new OverflowException('薪資資格檢查的收支加總超出可安全計算的整數範圍');
        }

        return $left + $right;
    }

    private function safeSubtract(int $left, int $right): int
    {
        if ($right > 0 && $left < PHP_INT_MIN + $right) {
            throw new OverflowException('薪資資格檢查的毛利超出可安全計算的整數範圍');
        }

        return $left - $right;
    }
}
