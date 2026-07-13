<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 1 needs this model as the target of Vehicle::salarySettlementItems() and
 * centralizes item types so automatic and manual records are unambiguous. Full
 * fillable/casts/relationships belong to PLAN v1.3 section 2; mass assignment is
 * therefore closed for now.
 */
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

    protected $guarded = ['*'];
}
