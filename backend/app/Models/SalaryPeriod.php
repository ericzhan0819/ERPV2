<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'period_month',
    'commission_plan_id',
    'status',
    'created_by',
    'confirmed_by',
    'confirmed_at',
    'paid_by',
    'paid_at',
    'payment_date',
    'cash_account_id',
    'idempotency_key',
])]
class SalaryPeriod extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PAID = 'paid';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_CONFIRMED, self::STATUS_PAID];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_date' => 'date',
        ];
    }

    /**
     * SQLite 不會像 MySQL DATE 自動截掉時間；明確以 Y-m-d 持久化，讓跨 driver 的
     * 月首等值查詢都能命中 salary_periods.period_month unique index。
     */
    protected function periodMonth(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?Carbon => $value === null ? null : Carbon::parse($value)->startOfDay(),
            set: fn (mixed $value): string => Carbon::parse($value)->format('Y-m-d'),
        );
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CommissionPlan::class, 'commission_plan_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(SalarySettlement::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
