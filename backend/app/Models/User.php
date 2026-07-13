<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_admin', 'is_active', 'role', 'phone', 'job_title', 'hire_date', 'notes'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_SALES = 'sales';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_SALES];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'hire_date' => 'date',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    public function isSales(): bool
    {
        return $this->role === self::ROLE_SALES;
    }

    /**
     * @param  string[]  $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * 允許看到收購價 / 成交價 / 毛利 / 資金帳戶餘額 / 完整收支金額等最敏感財務欄位的角色。
     * 刻意採白名單而非「非 sales」的黑名單：未知或未來新增角色預設看不到財務資料。
     */
    public function canViewFinancials(): bool
    {
        return $this->hasAnyRole([self::ROLE_ADMIN, self::ROLE_MANAGER]);
    }

    /**
     * 允許看到開價 / 底價 / 成交價的角色。這些欄位是業務跟客人議價、追蹤收款的依據，
     * 因此 sales 也需要看到；但收購價、購車付款、完整成本、單車毛利、資金帳戶餘額
     * 等仍只給 canViewFinancials()。同樣採白名單：未知或未來新增角色預設看不到。
     */
    public function canViewSalesPricing(): bool
    {
        return $this->hasAnyRole([self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_SALES]);
    }

    /**
     * 允許看到訂金 / 尾款 / 退款等銷售收款安全金額的角色。刻意與 canViewSalesPricing()
     * 分開命名，是因為兩者目前雖然是同一組角色，但語意不同：這個方法只授權「銷售收款
     * 追蹤」需要的金額，不能被拿來當作開放成本、毛利、資金帳戶餘額等其他財務欄位的依據。
     */
    public function canViewSalesCollectionAmounts(): bool
    {
        return $this->hasAnyRole([self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_SALES]);
    }

    public function salaryProfile(): HasOne
    {
        return $this->hasOne(SalaryProfile::class);
    }

    public function salarySettlements(): HasMany
    {
        return $this->hasMany(SalarySettlement::class);
    }

    public function purchaseAgentVehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'purchase_agent_id');
    }

    public function salesAgentVehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'sales_agent_id');
    }
}
