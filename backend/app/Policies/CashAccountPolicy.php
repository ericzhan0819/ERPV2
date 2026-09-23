<?php

namespace App\Policies;

use App\Models\CashAccount;
use App\Models\User;

class CashAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function view(User $user, CashAccount $cashAccount): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function viewBalances(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, CashAccount $cashAccount): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, CashAccount $cashAccount): bool
    {
        return $user->isAdmin();
    }
}
