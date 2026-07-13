<?php

namespace App\Services;

use App\Models\CommissionPlan;
use App\Models\CommissionPlanTier;
use App\Models\MoneyEntry;
use App\Models\Vehicle;
use App\Support\CommissionPlanRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OverflowException;

final class SalaryCommissionCalculator
{
    private const BASIS_POINTS_DENOMINATOR = 10000;

    /**
     * Calculate commissions for one month's already-eligible sold vehicles.
     *
     * Eligibility is deliberately outside this calculator. The caller must pass
     * exactly one period's eligible vehicles; this calculator performs no silent
     * filtering and only reads approved money entries for those vehicles.
     *
     * @param  iterable<int, Vehicle>  $vehicles
     * @return array{
     *     vehicles: array<int, array<string, int>>,
     *     sales_agents: array<int, array<string, int|null>>,
     *     totals: array<string, int>
     * }
     */
    public function calculate(CommissionPlan $plan, iterable $vehicles): array
    {
        $tiers = $this->validatedTiers($plan);
        $vehicles = collect($vehicles)->values()->sortBy('id')->values();
        $this->assertValidVehicleSet($vehicles);

        $moneyTotals = $this->approvedMoneyTotals($vehicles->pluck('id')->all());
        $salesCounts = $vehicles
            ->countBy(fn (Vehicle $vehicle): int => (int) $vehicle->sales_agent_id);

        $salesAgents = [];
        foreach ($salesCounts->sortKeys() as $salesAgentId => $eligibleSalesCount) {
            $salesAgents[(int) $salesAgentId] = [
                'sales_agent_id' => (int) $salesAgentId,
                ...$this->salesTierSummaryForTiers($tiers, $eligibleSalesCount),
                'sales_bonus_total' => 0,
                'tier_upgrade_estimated_increase' => 0,
            ];
        }

        $vehicleResults = [];
        $totals = $this->emptyTotals();

        foreach ($vehicles as $vehicle) {
            $vehicleId = (int) $vehicle->id;
            $salesAgentId = (int) $vehicle->sales_agent_id;
            $incomeTotal = $moneyTotals[$vehicleId]['income'] ?? 0;
            $expenseTotal = $moneyTotals[$vehicleId]['expense'] ?? 0;
            $salesBonusBps = $salesAgents[$salesAgentId]['sales_bonus_bps'];
            $amounts = $this->calculateVehicleAmounts(
                $incomeTotal,
                $expenseTotal,
                (int) $plan->company_reserve_bps,
                (int) $plan->purchase_bonus_bps,
                $salesBonusBps,
            );

            $vehicleResults[$vehicleId] = [
                'vehicle_id' => $vehicleId,
                'purchase_agent_id' => (int) $vehicle->purchase_agent_id,
                'sales_agent_id' => $salesAgentId,
                ...$amounts,
            ];

            $salesAgents[$salesAgentId]['sales_bonus_total'] = $this->safeAdd(
                $salesAgents[$salesAgentId]['sales_bonus_total'],
                $amounts['sales_bonus'],
            );

            $nextTierBps = $salesAgents[$salesAgentId]['next_tier_sales_bonus_bps'];
            if ($nextTierBps !== null && $amounts['distributable_pool'] > 0) {
                $nextTierBonus = $this->basisPointsOf($amounts['distributable_pool'], $nextTierBps);
                $increase = $nextTierBonus - $amounts['sales_bonus'];
                $salesAgents[$salesAgentId]['tier_upgrade_estimated_increase'] = $this->safeAdd(
                    $salesAgents[$salesAgentId]['tier_upgrade_estimated_increase'],
                    $increase,
                );
            }

            foreach ($totals as $field => $total) {
                $totals[$field] = $this->safeAdd($total, $amounts[$field]);
            }
        }

        ksort($vehicleResults);
        ksort($salesAgents);

        return [
            'vehicles' => $vehicleResults,
            'sales_agents' => $salesAgents,
            'totals' => $totals,
        ];
    }

    /**
     * @return array{
     *     eligible_sales_count: int,
     *     sales_bonus_bps: int,
     *     next_tier_min_sales_count: int|null,
     *     sales_until_next_tier: int|null,
     *     next_tier_sales_bonus_bps: int|null
     * }
     */
    public function salesTierSummary(CommissionPlan $plan, int $eligibleSalesCount): array
    {
        return $this->salesTierSummaryForTiers($this->validatedTiers($plan), $eligibleSalesCount);
    }

    /**
     * Pure single-vehicle formula. Exposed so formula edge cases can be tested
     * without a database and later reused for previews.
     *
     * @return array<string, int>
     */
    public function calculateVehicleAmounts(
        int $incomeTotal,
        int $expenseTotal,
        int $companyReserveBps,
        int $purchaseBonusBps,
        int $salesBonusBps,
    ): array {
        $this->assertNonNegative('income_total', $incomeTotal);
        $this->assertNonNegative('expense_total', $expenseTotal);
        $this->assertBasisPoints('company_reserve_bps', $companyReserveBps);
        $this->assertBasisPoints('purchase_bonus_bps', $purchaseBonusBps);
        $this->assertBasisPoints('sales_bonus_bps', $salesBonusBps);

        if ($purchaseBonusBps + $salesBonusBps > self::BASIS_POINTS_DENOMINATOR) {
            throw new InvalidArgumentException('收車獎金與賣車獎金合計不得超過分配池 100%');
        }

        $grossProfit = $this->safeSubtract($incomeTotal, $expenseTotal);
        if ($grossProfit <= 0) {
            return [
                'income_total' => $incomeTotal,
                'expense_total' => $expenseTotal,
                'gross_profit' => $grossProfit,
                'company_reserve' => 0,
                'distributable_pool' => 0,
                'purchase_bonus_bps' => $purchaseBonusBps,
                'purchase_bonus' => 0,
                'sales_bonus_bps' => $salesBonusBps,
                'sales_bonus' => 0,
                'company_remaining' => 0,
                'company_total' => 0,
            ];
        }

        $companyReserve = $this->basisPointsOf($grossProfit, $companyReserveBps);
        $distributablePool = $grossProfit - $companyReserve;
        $purchaseBonus = $this->basisPointsOf($distributablePool, $purchaseBonusBps);
        $salesBonus = $this->basisPointsOf($distributablePool, $salesBonusBps);
        $companyRemaining = $distributablePool - $purchaseBonus - $salesBonus;
        $companyTotal = $this->safeAdd($companyReserve, $companyRemaining);

        if ($this->safeAdd($companyTotal, $this->safeAdd($purchaseBonus, $salesBonus)) !== $grossProfit) {
            throw new OverflowException('單車毛利分配加總不一致');
        }

        return [
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'gross_profit' => $grossProfit,
            'company_reserve' => $companyReserve,
            'distributable_pool' => $distributablePool,
            'purchase_bonus_bps' => $purchaseBonusBps,
            'purchase_bonus' => $purchaseBonus,
            'sales_bonus_bps' => $salesBonusBps,
            'sales_bonus' => $salesBonus,
            'company_remaining' => $companyRemaining,
            'company_total' => $companyTotal,
        ];
    }

    /** @return Collection<int, CommissionPlanTier> */
    private function validatedTiers(CommissionPlan $plan): Collection
    {
        $tiers = $plan->relationLoaded('tiers') ? $plan->tiers : $plan->tiers()->get();
        $tiers = $tiers->sortBy('min_sales_count')->values();

        CommissionPlanRules::validate(
            (int) $plan->company_reserve_bps,
            (int) $plan->purchase_bonus_bps,
            $tiers->map(fn (CommissionPlanTier $tier) => [
                'min_sales_count' => (int) $tier->min_sales_count,
                'sales_bonus_bps' => (int) $tier->sales_bonus_bps,
                'sort_order' => (int) $tier->sort_order,
            ])->all(),
        );

        return $tiers;
    }

    /** @param Collection<int, Vehicle> $vehicles */
    private function assertValidVehicleSet(Collection $vehicles): void
    {
        $ids = [];
        foreach ($vehicles as $vehicle) {
            if (! $vehicle instanceof Vehicle || ! $vehicle->exists || $vehicle->getKey() === null) {
                throw new InvalidArgumentException('計算獎金的車輛必須是已儲存的 Vehicle');
            }
            if ($vehicle->purchase_agent_id === null || $vehicle->sales_agent_id === null) {
                throw new InvalidArgumentException("車輛 {$vehicle->getKey()} 缺少收車人或賣車人歸屬");
            }
            if (isset($ids[$vehicle->getKey()])) {
                throw new InvalidArgumentException("車輛 {$vehicle->getKey()} 不可重複計算");
            }
            $ids[$vehicle->getKey()] = true;
        }
    }

    /**
     * @param  array<int, int>  $vehicleIds
     * @return array<int, array{income?: int, expense?: int}>
     */
    private function approvedMoneyTotals(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $rows = DB::table('money_entries')
            ->whereIn('vehicle_id', $vehicleIds)
            ->where('approval_status', MoneyEntry::APPROVAL_APPROVED)
            ->groupBy('vehicle_id', 'direction')
            ->orderBy('vehicle_id')
            ->orderBy('direction')
            ->get([
                'vehicle_id',
                'direction',
                DB::raw('SUM(amount) as total'),
            ]);

        $totals = [];
        foreach ($rows as $row) {
            $amount = filter_var($row->total, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 0) {
                throw new OverflowException('車輛收支合計超出可安全計算的整數範圍');
            }
            $totals[(int) $row->vehicle_id][(string) $row->direction] = $amount;
        }

        return $totals;
    }

    /** @param Collection<int, CommissionPlanTier> $tiers */
    private function tierForCount(Collection $tiers, int $count): ?CommissionPlanTier
    {
        return $tiers->last(fn (CommissionPlanTier $tier) => $tier->min_sales_count <= $count);
    }

    /** @param Collection<int, CommissionPlanTier> $tiers */
    private function nextTierForCount(Collection $tiers, int $count): ?CommissionPlanTier
    {
        return $tiers->first(fn (CommissionPlanTier $tier) => $tier->min_sales_count > $count);
    }

    /**
     * @param  Collection<int, CommissionPlanTier>  $tiers
     * @return array{
     *     eligible_sales_count: int,
     *     sales_bonus_bps: int,
     *     next_tier_min_sales_count: int|null,
     *     sales_until_next_tier: int|null,
     *     next_tier_sales_bonus_bps: int|null
     * }
     */
    private function salesTierSummaryForTiers(Collection $tiers, int $eligibleSalesCount): array
    {
        $this->assertNonNegative('eligible_sales_count', $eligibleSalesCount);
        $currentTier = $this->tierForCount($tiers, $eligibleSalesCount);
        $nextTier = $this->nextTierForCount($tiers, $eligibleSalesCount);

        return [
            'eligible_sales_count' => $eligibleSalesCount,
            'sales_bonus_bps' => $currentTier?->sales_bonus_bps ?? 0,
            'next_tier_min_sales_count' => $nextTier?->min_sales_count,
            'sales_until_next_tier' => $nextTier === null
                ? null
                : $nextTier->min_sales_count - $eligibleSalesCount,
            'next_tier_sales_bonus_bps' => $nextTier?->sales_bonus_bps,
        ];
    }

    private function basisPointsOf(int $amount, int $basisPoints): int
    {
        $quotient = intdiv($amount, self::BASIS_POINTS_DENOMINATOR);
        $remainder = $amount % self::BASIS_POINTS_DENOMINATOR;

        return ($quotient * $basisPoints)
            + intdiv($remainder * $basisPoints, self::BASIS_POINTS_DENOMINATOR);
    }

    private function safeAdd(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new OverflowException('薪資獎金加總超出可安全計算的整數範圍');
        }
        if ($right < 0 && $left < PHP_INT_MIN - $right) {
            throw new OverflowException('薪資獎金加總超出可安全計算的整數範圍');
        }

        return $left + $right;
    }

    private function safeSubtract(int $left, int $right): int
    {
        if ($right > 0 && $left < PHP_INT_MIN + $right) {
            throw new OverflowException('單車毛利超出可安全計算的整數範圍');
        }

        return $left - $right;
    }

    private function assertBasisPoints(string $field, int $value): void
    {
        if ($value < 0 || $value > self::BASIS_POINTS_DENOMINATOR) {
            throw new InvalidArgumentException("{$field} 必須介於 0 到 10000 basis points");
        }
    }

    private function assertNonNegative(string $field, int $value): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("{$field} 不可為負數");
        }
    }

    /** @return array<string, int> */
    private function emptyTotals(): array
    {
        return [
            'income_total' => 0,
            'expense_total' => 0,
            'gross_profit' => 0,
            'company_reserve' => 0,
            'distributable_pool' => 0,
            'purchase_bonus' => 0,
            'sales_bonus' => 0,
            'company_remaining' => 0,
            'company_total' => 0,
        ];
    }
}
