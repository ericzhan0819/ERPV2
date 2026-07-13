<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'commission_plan_id',
    'min_sales_count',
    'sales_bonus_bps',
    'sort_order',
])]
class CommissionPlanTier extends Model
{
    protected function casts(): array
    {
        return [
            'min_sales_count' => 'integer',
            'sales_bonus_bps' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function commissionPlan(): BelongsTo
    {
        return $this->belongsTo(CommissionPlan::class);
    }
}
