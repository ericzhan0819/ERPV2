<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

#[Fillable(['name', 'phone', 'line_id', 'customer_type', 'source', 'address', 'notes'])]
class Customer extends Model
{
    use HasFactory;

    protected $hidden = ['normalized_name', 'normalized_phone'];

    public const IDENTITY_UNIQUE_INDEX = 'customers_normalized_identity_unique';

    public const TYPE_BUYER = 'buyer';

    public const TYPE_SELLER = 'seller';

    public const TYPE_BOTH = 'both';

    public const TYPE_OTHER = 'other';

    public const TYPES = [self::TYPE_BUYER, self::TYPE_SELLER, self::TYPE_BOTH, self::TYPE_OTHER];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            $customer->normalized_name = self::normalizeIdentityName((string) $customer->name);
            $customer->normalized_phone = self::normalizeIdentityPhone($customer->phone);
        });
    }

    public static function normalizeIdentityName(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name));

        return mb_strtolower($collapsed ?? trim($name));
    }

    public static function normalizeIdentityPhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits !== '' ? $digits : mb_strtolower($phone);
    }

    public static function isIdentityUniqueViolation(QueryException $exception): bool
    {
        if (($exception->errorInfo[0] ?? null) !== '23000') {
            return false;
        }

        $message = $exception->getMessage();

        return str_contains($message, self::IDENTITY_UNIQUE_INDEX)
            || (str_contains($message, 'customers.normalized_name')
                && str_contains($message, 'customers.normalized_phone'));
    }

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
