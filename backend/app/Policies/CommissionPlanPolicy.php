<?php

namespace App\Policies;

use App\Models\CommissionPlan;
use App\Models\User;

class CommissionPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, CommissionPlan $plan): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }
}
