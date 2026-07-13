<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'salary_settlement_id',
    'type',
    'vehicle_id',
    'amount',
    'description',
    'calculation_snapshot',
    'created_by',
])]
class SalarySettlementItem extends Model
{
    public const TYPE_BASE_SALARY = 'base_salary';

    public const TYPE_FIXED_ALLOWANCE = 'fixed_allowance';

    public const TYPE_PURCHASE_BONUS = 'purchase_bonus';

    public const TYPE_SALES_BONUS = 'sales_bonus';

    public const TYPE_LABOR_INSURANCE = 'labor_insurance';

    public const TYPE_HEALTH_INSURANCE = 'health_insurance';

    public const TYPE_MANUAL_ADDITION = 'manual_addition';

    public const TYPE_MANUAL_DEDUCTION = 'manual_deduction';

    public const AUTOMATIC_TYPES = [
        self::TYPE_BASE_SALARY,
        self::TYPE_FIXED_ALLOWANCE,
        self::TYPE_PURCHASE_BONUS,
        self::TYPE_SALES_BONUS,
        self::TYPE_LABOR_INSURANCE,
        self::TYPE_HEALTH_INSURANCE,
    ];

    public const MANUAL_TYPES = [
        self::TYPE_MANUAL_ADDITION,
        self::TYPE_MANUAL_DEDUCTION,
    ];

    public const TYPES = [
        ...self::AUTOMATIC_TYPES,
        ...self::MANUAL_TYPES,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'calculation_snapshot' => 'array',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SalarySettlement::class, 'salary_settlement_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
