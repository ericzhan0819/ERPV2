<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'phone', 'line_id', 'customer_type', 'source', 'address', 'notes'])]
class Customer extends Model
{
    use HasFactory;

    public const TYPE_BUYER = 'buyer';

    public const TYPE_SELLER = 'seller';

    public const TYPE_BOTH = 'both';

    public const TYPE_OTHER = 'other';

    public const TYPES = [self::TYPE_BUYER, self::TYPE_SELLER, self::TYPE_BOTH, self::TYPE_OTHER];

    public function vehiclesAsSeller(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'seller_customer_id');
    }

    public function vehiclesAsBuyer(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'buyer_customer_id');
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
