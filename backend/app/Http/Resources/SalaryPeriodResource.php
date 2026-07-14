<?php

namespace App\Http\Resources;

use App\Models\SalaryPeriod;
use App\Services\SalaryEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalaryPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $eligibility = $this->draftEligibility();

        return [
            'id' => $this->id,
            'period_month' => $this->period_month?->format('Y-m'),
            'status' => $this->status,
            'commission_plan' => new CommissionPlanResource($this->whenLoaded('plan')),
            'settlements' => SalarySettlementResource::collection($this->whenLoaded('settlements')),
            'totals' => $this->settlementTotals(),
            'created_by' => $this->userSummary('createdBy'),
            'confirmed_by' => $this->userSummary('confirmedBy'),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'paid_by' => $this->userSummary('paidBy'),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_date' => $this->payment_date?->toDateString(),
            'cash_account' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? [
                'id' => $this->cashAccount->id,
                'name' => $this->cashAccount->name,
                'type' => $this->cashAccount->type,
            ] : null),
            'anomalies' => $this->when($eligibility !== null, fn () => $eligibility['anomalies']),
            'vehicle_results' => $this->when(
                $eligibility !== null,
                fn () => $this->vehicleResults($eligibility['vehicle_results']),
            ),
            'has_blocking_issues' => $this->when(
                $eligibility !== null,
                fn () => $eligibility['has_blocking_issues'],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function draftEligibility(): ?array
    {
        if ($this->status !== SalaryPeriod::STATUS_DRAFT) {
            return null;
        }
        if ($this->resource->relationLoaded('draftEligibility')) {
            return $this->resource->getRelation('draftEligibility');
        }

        return app(SalaryEligibilityService::class)->inspectPeriod(
            $this->period_month->format('Y-m'),
            (int) $this->id,
        );
    }

    /** @return array<string, int>|null */
    private function settlementTotals(): ?array
    {
        if (! $this->resource->relationLoaded('settlements')) {
            return null;
        }

        return [
            'purchase_bonus_total' => (int) $this->settlements->sum('purchase_bonus_total'),
            'sales_bonus_total' => (int) $this->settlements->sum('sales_bonus_total'),
            'manual_addition_total' => (int) $this->settlements->sum('manual_addition_total'),
            'manual_deduction_total' => (int) $this->settlements->sum('manual_deduction_total'),
            'gross_pay' => (int) $this->settlements->sum('gross_pay'),
            'deduction_total' => (int) $this->settlements->sum('deduction_total'),
            'net_pay' => (int) $this->settlements->sum('net_pay'),
        ];
    }

    /** @return array{id: int, name: string}|null|mixed */
    private function userSummary(string $relation): mixed
    {
        return $this->whenLoaded($relation, function () use ($relation): ?array {
            $user = $this->resource->getRelation($relation);

            return $user ? ['id' => $user->id, 'name' => $user->name] : null;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function vehicleResults(array $results): array
    {
        return array_values(array_map(function (array $result): array {
            $vehicle = $result['vehicle'];

            return [
                'vehicle_id' => $result['vehicle_id'],
                'stock_no' => $result['stock_no'],
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'sold_at' => $vehicle->sold_at?->toIso8601String(),
                'purchase_agent_id' => $vehicle->purchase_agent_id,
                'sales_agent_id' => $vehicle->sales_agent_id,
                'eligible' => $result['eligible'],
                'gross_profit' => $result['gross_profit'],
                'issues' => $result['issues'],
            ];
        }, $results));
    }
}
