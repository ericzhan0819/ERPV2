<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 官網公開唯讀用途，刻意不共用內部 VehicleResource：只回傳企劃書_v1.2.md 第 10.1 節
 * 列出的允許欄位，絕不回傳收購價 / 底價 / 成交價 / 客戶個資 / 收支 / 毛利 / 資金帳戶
 * / 內部備註 / approval_status / idempotency_key（第 10.2 節）。
 */
class PublicVehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cover = $this->photos->firstWhere('is_cover', true);

        return [
            'id' => $this->id,
            'stock_no' => $this->stock_no,
            'brand' => $this->brand,
            'model' => $this->model,
            'year' => $this->year,
            'mileage_km' => $this->mileage_km,
            'color' => $this->color,
            'fuel_type' => $this->fuel_type,
            'transmission' => $this->transmission,
            'displacement' => $this->displacement,
            'asking_price' => $this->asking_price,
            'cover_photo' => $cover !== null ? new PublicVehiclePhotoResource($cover) : null,
            'photos' => PublicVehiclePhotoResource::collection($this->photos),
            'listing_date' => $this->listing_date?->toDateString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
