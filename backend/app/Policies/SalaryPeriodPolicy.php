<?php

namespace App\Policies;

use App\Models\SalaryPeriod;
use App\Models\User;

class SalaryPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, SalaryPeriod $salaryPeriod): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function recalculate(User $user, SalaryPeriod $salaryPeriod): bool
    {
        return $user->isAdmin();
    }

    public function confirm(User $user, SalaryPeriod $salaryPeriod): bool
    {
        return $user->isAdmin();
    }

    public function pay(User $user, SalaryPeriod $salaryPeriod): bool
    {
        return $user->isAdmin();
    }
}
