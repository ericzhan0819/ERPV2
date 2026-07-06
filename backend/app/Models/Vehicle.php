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
    'purchase_date',
    'purchase_source_type',
    'seller_name',
    'seller_phone',
    'seller_customer_id',
    'purchase_price',
    'asking_price',
    'floor_price',
    'listing_date',
    'sales_note',
    'reserved_at',
    'sold_at',
    'sold_price',
    'buyer_name',
    'buyer_phone',
    'buyer_customer_id',
    'notes',
])]
class Vehicle extends Model
{
    use HasFactory;

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
        ];
    }

    public function moneyEntries(): HasMany
    {
        return $this->hasMany(MoneyEntry::class);
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
