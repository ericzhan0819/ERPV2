<?php

namespace App\Policies;

use App\Models\MoneyEntry;
use App\Models\User;

class MoneyEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function view(User $user, MoneyEntry $moneyEntry): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function update(User $user, MoneyEntry $moneyEntry): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function delete(User $user, MoneyEntry $moneyEntry): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }
}
