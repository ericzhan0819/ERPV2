<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->isAdmin();
    }
}
