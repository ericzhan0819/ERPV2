<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MoneyEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_date' => $this->entry_date?->toDateString(),
            'direction' => $this->direction,
            'category' => $this->category,
            'amount' => $this->amount,
            'vehicle_id' => $this->vehicle_id,
            'cash_account_id' => $this->cash_account_id,
            'counterparty_name' => $this->counterparty_name,
            'description' => $this->description,
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'stock_no' => $this->vehicle->stock_no,
                'brand' => $this->vehicle->brand,
                'model' => $this->vehicle->model,
            ] : null),
            'cash_account' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? [
                'id' => $this->cashAccount->id,
                'name' => $this->cashAccount->name,
                'type' => $this->cashAccount->type,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
