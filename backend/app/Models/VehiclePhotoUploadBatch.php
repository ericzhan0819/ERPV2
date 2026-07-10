<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'vehicle_id',
    'idempotency_key',
    'idempotency_payload',
    'photo_ids',
])]
class VehiclePhotoUploadBatch extends Model
{
    protected function casts(): array
    {
        return [
            'photo_ids' => 'array',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
