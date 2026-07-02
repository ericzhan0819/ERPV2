<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_no' => $this->stock_no,
            'status' => $this->status,
            'brand' => $this->brand,
            'model' => $this->model,
            'year' => $this->year,
            'license_plate' => $this->license_plate,
            'vin' => $this->vin,
            'mileage_km' => $this->mileage_km,
            'color' => $this->color,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'purchase_source_type' => $this->purchase_source_type,
            'seller_name' => $this->seller_name,
            'seller_phone' => $this->seller_phone,
            'purchase_price' => $this->purchase_price,
            'asking_price' => $this->asking_price,
            'floor_price' => $this->floor_price,
            'listing_date' => $this->listing_date?->toDateString(),
            'sales_note' => $this->sales_note,
            'reserved_at' => $this->reserved_at?->toISOString(),
            'sold_at' => $this->sold_at?->toISOString(),
            'sold_price' => $this->sold_price,
            'buyer_name' => $this->buyer_name,
            'buyer_phone' => $this->buyer_phone,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
