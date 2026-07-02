<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'opening_balance', 'is_active'])]
class CashAccount extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function moneyEntries(): HasMany
    {
        return $this->hasMany(MoneyEntry::class);
    }
}
