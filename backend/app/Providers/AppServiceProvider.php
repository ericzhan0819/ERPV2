<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CommissionPlan;
use App\Models\Customer;
use App\Models\MoneyEntry;
use App\Models\SalaryPeriod;
use App\Models\SalaryProfile;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Models\Vehicle;
use App\Observers\AuditObserver;
use App\Policies\AuditLogPolicy;
use App\Policies\CommissionPlanPolicy;
use App\Policies\SalaryPeriodPolicy;
use App\Policies\SalaryProfilePolicy;
use App\Policies\SalarySettlementPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * 註冊應用程式服務。
     */
    public function register(): void
    {
        //
    }

    /**
     * 啟動應用程式服務。
     */
    public function boot(): void
    {
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(SalaryProfile::class, SalaryProfilePolicy::class);
        Gate::policy(CommissionPlan::class, CommissionPlanPolicy::class);
        Gate::policy(SalaryPeriod::class, SalaryPeriodPolicy::class);
        Gate::policy(SalarySettlement::class, SalarySettlementPolicy::class);

        foreach ([User::class, Vehicle::class, MoneyEntry::class, CashAccount::class, Customer::class] as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
