<?php

namespace App\Policies;

use App\Models\User;

class SalaryProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function upsert(User $user): bool
    {
        return $user->isAdmin();
    }
}
