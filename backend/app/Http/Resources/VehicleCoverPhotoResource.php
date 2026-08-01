<?php

namespace App\Http\Resources;

use App\Support\StorageAssetUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleCoverPhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'thumbnail_url' => StorageAssetUrl::forRequest(
                $request,
                $this->disk,
                $this->thumbnail_path,
                followRequestOrigin: true,
            ),
        ];
    }
}
