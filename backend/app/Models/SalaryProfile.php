<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * 「啟用中的薪資設定」的唯一定義：薪資設定與使用者都必須是啟用狀態。
     *
     * 薪資草稿據此建立 settlement 與判斷可領獎金的人員，草稿提示也據此判斷歸屬人
     * 會不會拿到獎金。兩邊必須共用同一個條件，不得各自手寫。
     *
     * @param  Builder<SalaryProfile>  $query
     * @return Builder<SalaryProfile>
     */
    public function scopeSettlementActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereHas('user', fn (Builder $userQuery) => $userQuery->where('is_active', true));
    }
}
