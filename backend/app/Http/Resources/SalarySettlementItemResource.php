<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalarySettlementItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'vehicle_id' => $this->vehicle_id,
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'stock_no' => $this->vehicle->stock_no,
                'brand' => $this->vehicle->brand,
                'model' => $this->vehicle->model,
            ] : null),
            'amount' => $this->amount,
            'description' => $this->description,
            'calculation' => $this->calculationSnapshot(),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function calculationSnapshot(): ?array
    {
        if (! is_array($this->calculation_snapshot)) {
            return null;
        }

        return collect($this->calculation_snapshot)->only([
            'period_month',
            'income_total',
            'expense_total',
            'gross_profit',
            'company_reserve_bps',
            'company_reserve',
            'distributable_pool',
            'purchase_bonus_bps',
            'purchase_bonus',
            'sales_bonus_bps',
            'sales_bonus',
            'company_remaining',
            'purchase_agent_id',
            'sales_agent_id',
        ])->all();
    }
}
