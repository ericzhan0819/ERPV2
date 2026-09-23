<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalaryPeriodListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period_month' => $this->period_month?->format('Y-m'),
            'status' => $this->status,
            'commission_plan' => $this->whenLoaded('plan', fn () => [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
            ]),
            'settlement_count' => $this->aggregateValue('settlements_count', 'settlements'),
            'net_pay_total' => $this->aggregateValue('settlements_sum_net_pay', 'settlements', 'net_pay'),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_date' => $this->payment_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function aggregateValue(string $attribute, string $relation, ?string $sumField = null): ?int
    {
        if (array_key_exists($attribute, $this->resource->getAttributes())) {
            return (int) $this->resource->getAttribute($attribute);
        }

        if (! $this->resource->relationLoaded($relation)) {
            return null;
        }

        return $sumField === null
            ? $this->resource->getRelation($relation)->count()
            : (int) $this->resource->getRelation($relation)->sum($sumField);
    }
}
