<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'vehicle_id',
    'upload_batch_id',
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
    use HasFactory, SoftDeletes;

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

    /**
     * 只有 upload_batch_id 為 NULL 的照片才算「已提交、正式可見」：mid-upload
     * 逐檔建立時會先記錄它屬於哪個 batch，直到整批上傳全部成功、一次性清空
     * upload_batch_id 才視為可對外曝光（見 uploadPhotos() 收尾與這次新增的
     * 2026_07_11_000002 migration 說明）。Vehicle::photos() 關聯直接套用這個
     * scope，讓一般列表、public API、setCover、reorder 都不會看到還在批次
     * 中途、尚未提交完成的照片。
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('upload_batch_id');
    }
}
