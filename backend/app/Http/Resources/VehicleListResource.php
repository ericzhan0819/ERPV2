<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class VehicleListResource extends VehicleResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'cover_photo' => $this->coverPhoto === null
                ? null
                : new VehicleCoverPhotoResource($this->coverPhoto),
        ];
    }
}
