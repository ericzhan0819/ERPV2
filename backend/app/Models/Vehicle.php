<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'stock_no',
    'status',
    'brand',
    'model',
    'year',
    'license_plate',
    'vin',
    'mileage_km',
    'color',
    'displacement',
    'transmission',
    'fuel_type',
    'parking_location',
    'has_registration_document',
    'has_spare_key',
    'is_transfer_completed',
    'is_inspection_completed',
    'is_preparation_completed',
    'lien_note',
    'condition_note',
    'purchase_date',
    'purchase_source_type',
    'seller_name',
    'seller_phone',
    'seller_customer_id',
    'purchase_price',
    'purchase_agent_id',
    'asking_price',
    'floor_price',
    'listing_date',
    'sales_note',
    'reserved_at',
    'sold_at',
    'sold_price',
    'sales_agent_id',
    'buyer_name',
    'buyer_phone',
    'buyer_customer_id',
    'notes',
])]
class Vehicle extends Model
{
    use HasFactory;

    protected $attributes = [
        'has_registration_document' => false,
        'has_spare_key' => false,
        'is_transfer_completed' => false,
        'is_inspection_completed' => false,
        'is_preparation_completed' => false,
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'listing_date' => 'date',
            'reserved_at' => 'datetime',
            'sold_at' => 'datetime',
            'purchase_price' => 'integer',
            'asking_price' => 'integer',
            'floor_price' => 'integer',
            'sold_price' => 'integer',
            'mileage_km' => 'integer',
            'year' => 'integer',
            'has_registration_document' => 'boolean',
            'has_spare_key' => 'boolean',
            'is_transfer_completed' => 'boolean',
            'is_inspection_completed' => 'boolean',
            'is_preparation_completed' => 'boolean',
        ];
    }

    public function moneyEntries(): HasMany
    {
        return $this->hasMany(MoneyEntry::class);
    }

    public function purchaseAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchase_agent_id');
    }

    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_agent_id');
    }

    public function salarySettlementItems(): HasMany
    {
        return $this->hasMany(SalarySettlementItem::class);
    }

    /**
     * 只回傳已提交完成、正式可見的照片（upload_batch_id 為 NULL），排除還在
     * 批次上傳中途、尚未整批完成的照片列（見
     * VehiclePhoto::scopeVisible()）。一般列表、public API、setCover、
     * reorder 都透過這個關聯查詢，因此天生就不會曝光、也不能操作還沒提交
     * 完成的照片；uploadPhotos() 內部逐檔處理時的照片上限/封面/sort_order
     * 檢查刻意改用 VehiclePhoto::where('vehicle_id', ...) 直接查詢、不經過
     * 這個關聯，才能看到同一批次自己已建立、但尚未提交的照片，避免上限、
     * 封面、sort_order 判斷漏算。
     */
    public function photos(): HasMany
    {
        return $this->hasMany(VehiclePhoto::class)->visible()->orderBy('sort_order');
    }

    public function sellerCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'seller_customer_id');
    }

    public function buyerCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'buyer_customer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
