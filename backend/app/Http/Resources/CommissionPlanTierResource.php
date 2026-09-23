<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionPlanTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'min_sales_count' => $this->min_sales_count,
            'sales_bonus_bps' => $this->sales_bonus_bps,
            'sort_order' => $this->sort_order,
        ];
    }
}
