<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'salary_period_id',
    'user_id',
    'eligible_sales_count',
    'sales_bonus_bps_snapshot',
    'base_salary_snapshot',
    'fixed_allowance_snapshot',
    'labor_insurance_deduction_snapshot',
    'health_insurance_deduction_snapshot',
    'purchase_bonus_total',
    'sales_bonus_total',
    'manual_addition_total',
    'manual_deduction_total',
    'gross_pay',
    'deduction_total',
    'net_pay',
    'money_entry_id',
])]
class SalarySettlement extends Model
{
    protected function casts(): array
    {
        return [
            'eligible_sales_count' => 'integer',
            'sales_bonus_bps_snapshot' => 'integer',
            'base_salary_snapshot' => 'integer',
            'fixed_allowance_snapshot' => 'integer',
            'labor_insurance_deduction_snapshot' => 'integer',
            'health_insurance_deduction_snapshot' => 'integer',
            'purchase_bonus_total' => 'integer',
            'sales_bonus_total' => 'integer',
            'manual_addition_total' => 'integer',
            'manual_deduction_total' => 'integer',
            'gross_pay' => 'integer',
            'deduction_total' => 'integer',
            'net_pay' => 'integer',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(SalaryPeriod::class, 'salary_period_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalarySettlementItem::class);
    }

    public function moneyEntry(): BelongsTo
    {
        return $this->belongsTo(MoneyEntry::class);
    }
}
