<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'vehicle_id',
    'cash_account_id',
    'entry_date',
    'direction',
    'category',
    'amount',
    'counterparty_name',
    'description',
    'idempotency_key',
    'source_type',
])]
class MoneyEntry extends Model
{
    use HasFactory;

    /**
     * 由 /api/money-entries 一般 CRUD 建立，成交前可修改/刪除。
     */
    public const SOURCE_MANUAL = 'manual';

    /**
     * 由購車付款/單車支出/收訂金/退款快捷建立，不得由一般 CRUD 修改/刪除。
     */
    public const SOURCE_VEHICLE_SHORTCUT = 'vehicle_shortcut';

    /**
     * 由 reserve（收訂金並保留）/ final-payment（收尾款）流程建立，不得由一般 CRUD 修改/刪除。
     */
    public const SOURCE_VEHICLE_WORKFLOW = 'vehicle_workflow';

    /**
     * source_type 欄位導入前已存在、且綁定車輛但無 durable provenance marker 的既有收支。
     * 預設保護狀態，不得透過一般 CRUD 修改/刪除，需人工確認後才能改成
     * manual / vehicle_shortcut / vehicle_workflow。
     */
    public const SOURCE_LEGACY_UNKNOWN = 'legacy_unknown';

    /**
     * 由 admin 發薪流程建立並直接核准；不得透過一般收支 CRUD 或審核流程異動。
     */
    public const SOURCE_SALARY_SETTLEMENT = 'salary_settlement';

    public const SOURCE_TYPES = [
        self::SOURCE_MANUAL,
        self::SOURCE_VEHICLE_SHORTCUT,
        self::SOURCE_VEHICLE_WORKFLOW,
        self::SOURCE_LEGACY_UNKNOWN,
        self::SOURCE_SALARY_SETTLEMENT,
    ];

    /**
     * 一般收支尚待 admin 審核，不計入正式餘額。
     */
    public const APPROVAL_PENDING = 'pending';

    /**
     * 已計入正式餘額；admin 建立時直接落地，manager/sales 建立需經核准。
     */
    public const APPROVAL_APPROVED = 'approved';

    /**
     * 已駁回，永久不計入正式餘額；如需修正必須新增一筆 entry，不可改回 pending。
     */
    public const APPROVAL_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'amount' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function scopeApproved($query)
    {
        return $query->where('approval_status', self::APPROVAL_APPROVED);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
