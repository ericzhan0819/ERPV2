<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'base_salary',
    'fixed_allowance',
    'labor_insurance_deduction',
    'health_insurance_deduction',
    'commission_enabled',
    'is_active',
])]
class SalaryProfile extends Model
{
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'base_salary' => 'integer',
            'fixed_allowance' => 'integer',
            'labor_insurance_deduction' => 'integer',
            'health_insurance_deduction' => 'integer',
            'commission_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
