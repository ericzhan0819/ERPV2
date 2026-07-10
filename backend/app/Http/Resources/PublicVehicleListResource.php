<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 官網公開列表用途，刻意與 PublicVehicleResource（詳情頁）分開：列表只回傳封面照，
 * 不回傳完整 photos 陣列。若列表也回傳每台車完整相簿（單車最多 60 張），
 * 未登入使用者可用 per_page=100 的單一請求換取上萬筆照片序列化與 DB 讀取，
 * 形成低成本的匿名放大攻擊面（Codex adversarial review 指出）。列表頁不需要
 * 完整相簿，車輛詳情頁（PublicVehicleResource + show()）才回傳完整 photos。
 */
class PublicVehicleListResource extends JsonResource
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
            'listing_date' => $this->listing_date?->toDateString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
