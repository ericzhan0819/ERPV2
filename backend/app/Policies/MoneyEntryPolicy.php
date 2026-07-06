<?php

namespace App\Policies;

use App\Models\MoneyEntry;
use App\Models\User;

class MoneyEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function view(User $user, MoneyEntry $moneyEntry): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function update(User $user, MoneyEntry $moneyEntry): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function delete(User $user, MoneyEntry $moneyEntry): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function approve(User $user, MoneyEntry $moneyEntry): bool
    {
        return $user->isAdmin();
    }

    public function reject(User $user, MoneyEntry $moneyEntry): bool
    {
        return $user->isAdmin();
    }
}
