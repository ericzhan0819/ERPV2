<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * 公開官網用途，刻意不共用 VehiclePhotoResource：只回傳展示用欄位，不回傳
 * original_filename、mime_type、uploaded_by 等內部資訊（企劃書_v1.2.md 第 7 節）。
 */
class PublicVehiclePhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => Storage::disk($this->disk)->url($this->path),
            'thumbnail_url' => Storage::disk($this->disk)->url($this->thumbnail_path),
            'is_cover' => $this->is_cover,
            'sort_order' => $this->sort_order,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
