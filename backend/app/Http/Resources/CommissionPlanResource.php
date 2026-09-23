<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'effective_from' => $this->effective_from?->toDateString(),
            'company_reserve_bps' => $this->company_reserve_bps,
            'purchase_bonus_bps' => $this->purchase_bonus_bps,
            'is_active' => $this->is_active,
            'is_used' => isset($this->salary_periods_count) ? $this->salary_periods_count > 0 : null,
            'tiers' => CommissionPlanTierResource::collection($this->whenLoaded('tiers')),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
