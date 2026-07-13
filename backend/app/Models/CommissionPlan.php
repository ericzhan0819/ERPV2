<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'effective_from',
    'company_reserve_bps',
    'purchase_bonus_bps',
    'is_active',
    'created_by',
])]
class CommissionPlan extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'company_reserve_bps' => 'integer',
            'purchase_bonus_bps' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(CommissionPlanTier::class)->orderBy('sort_order');
    }

    public function salaryPeriods(): HasMany
    {
        return $this->hasMany(SalaryPeriod::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActiveForMonth(Builder $query, string $periodMonth): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $periodMonth);
    }
}
