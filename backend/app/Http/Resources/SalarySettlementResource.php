<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalarySettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role,
            ]),
            'eligible_sales_count' => $this->eligible_sales_count,
            'sales_bonus_bps' => $this->sales_bonus_bps_snapshot,
            'base_salary' => $this->base_salary_snapshot,
            'fixed_allowance' => $this->fixed_allowance_snapshot,
            'labor_insurance_deduction' => $this->labor_insurance_deduction_snapshot,
            'health_insurance_deduction' => $this->health_insurance_deduction_snapshot,
            'purchase_bonus_total' => $this->purchase_bonus_total,
            'sales_bonus_total' => $this->sales_bonus_total,
            'manual_addition_total' => $this->manual_addition_total,
            'manual_deduction_total' => $this->manual_deduction_total,
            'gross_pay' => $this->gross_pay,
            'deduction_total' => $this->deduction_total,
            'net_pay' => $this->net_pay,
            'has_payment_entry' => $this->money_entry_id !== null,
            'items' => SalarySettlementItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
