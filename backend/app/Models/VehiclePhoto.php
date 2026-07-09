<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'vehicle_id',
    'disk',
    'path',
    'thumbnail_path',
    'original_filename',
    'mime_type',
    'size',
    'width',
    'height',
    'sort_order',
    'is_cover',
    'uploaded_by',
])]
#[Hidden(['cover_slot'])]
class VehiclePhoto extends Model
{
    use HasFactory;

    protected $attributes = [
        'disk' => 'public',
        'sort_order' => 0,
        'is_cover' => false,
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'is_cover' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
