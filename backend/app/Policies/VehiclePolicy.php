<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function listVehicle(User $user, Vehicle $vehicle): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function purchasePayment(User $user, Vehicle $vehicle): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function print(User $user, Vehicle $vehicle): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER]);
    }

    public function reserve(User $user, Vehicle $vehicle): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function finalPayment(User $user, Vehicle $vehicle): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function closeSale(User $user, Vehicle $vehicle): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function expense(User $user, Vehicle $vehicle): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function deposit(User $user, Vehicle $vehicle): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function refund(User $user, Vehicle $vehicle): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    public function viewMoneyEntries(User $user, Vehicle $vehicle): bool
    {
        return true;
    }
}
