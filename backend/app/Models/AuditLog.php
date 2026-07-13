<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_LOGIN = 'login';

    public const ACTION_LOGOUT = 'logout';

    public const ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_UPDATED,
        self::ACTION_DELETED,
        self::ACTION_LOGIN,
        self::ACTION_LOGOUT,
    ];

    public const SUBJECT_TYPES = [
        'user',
        'vehicle',
        'vehicle_photo',
        'money_entry',
        'cash_account',
        'customer',
        'authentication',
        'salary_profile',
        'commission_plan',
    ];

    protected $fillable = [
        'actor_id',
        'actor_name',
        'actor_email',
        'actor_role',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'before_values',
        'after_values',
        'ip_address',
        'user_agent',
        'request_method',
        'request_path',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_values' => 'array',
            'after_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Audit records are append-only. Application code must never update them.
     */
    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function scopeNewest(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
