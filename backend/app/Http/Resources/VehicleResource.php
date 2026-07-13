<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSeeFinancials = $request->user()?->canViewFinancials() ?? false;
        $canSeeSalesPricing = $request->user()?->canViewSalesPricing() ?? false;
        $canSeeCommissionAttribution = $request->user()?->hasAnyRole(User::ROLES) ?? false;

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
            'displacement' => $this->displacement,
            'transmission' => $this->transmission,
            'fuel_type' => $this->fuel_type,
            'parking_location' => $this->parking_location,
            'has_registration_document' => $this->has_registration_document,
            'has_spare_key' => $this->has_spare_key,
            'is_transfer_completed' => $this->is_transfer_completed,
            'is_inspection_completed' => $this->is_inspection_completed,
            'is_preparation_completed' => $this->is_preparation_completed,
            'lien_note' => $this->lien_note,
            'condition_note' => $this->condition_note,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'purchase_source_type' => $this->purchase_source_type,
            'seller_name' => $this->seller_name,
            'seller_phone' => $this->seller_phone,
            'seller_customer_id' => $this->seller_customer_id,
            'purchase_price' => $this->when($canSeeFinancials, $this->purchase_price),
            'purchase_agent_id' => $this->when($canSeeCommissionAttribution, $this->purchase_agent_id),
            'purchase_agent' => $this->when($canSeeCommissionAttribution, fn () => $this->whenLoaded('purchaseAgent', fn () => $this->purchaseAgent ? [
                'id' => $this->purchaseAgent->id,
                'name' => $this->purchaseAgent->name,
            ] : null)),
            'asking_price' => $this->when($canSeeSalesPricing, $this->asking_price),
            'floor_price' => $this->when($canSeeSalesPricing, $this->floor_price),
            'listing_date' => $this->listing_date?->toDateString(),
            'sales_note' => $this->sales_note,
            'reserved_at' => $this->reserved_at?->toIso8601String(),
            'sold_at' => $this->sold_at?->toIso8601String(),
            'sold_price' => $this->when($canSeeSalesPricing, $this->sold_price),
            'sales_agent_id' => $this->when($canSeeCommissionAttribution, $this->sales_agent_id),
            'sales_agent' => $this->when($canSeeCommissionAttribution, fn () => $this->whenLoaded('salesAgent', fn () => $this->salesAgent ? [
                'id' => $this->salesAgent->id,
                'name' => $this->salesAgent->name,
            ] : null)),
            'buyer_name' => $this->buyer_name,
            'buyer_phone' => $this->buyer_phone,
            'buyer_customer_id' => $this->buyer_customer_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
