<?php

namespace App\Policies;

use App\Models\SalarySettlement;
use App\Models\User;

class SalarySettlementPolicy
{
    public function view(User $user, SalarySettlement $salarySettlement): bool
    {
        return $user->isAdmin();
    }

    public function adjust(User $user, SalarySettlement $salarySettlement): bool
    {
        return $user->isAdmin();
    }
}
