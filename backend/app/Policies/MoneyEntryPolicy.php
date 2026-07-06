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
        return $this->ownsOrIsAdmin($user, $moneyEntry);
    }

    public function delete(User $user, MoneyEntry $moneyEntry): bool
    {
        return $this->ownsOrIsAdmin($user, $moneyEntry);
    }

    /**
     * 一般收支在核准前只有本人或 admin 可以修改/刪除，manager/sales 之間互不可異動彼此送出的
     * 待審收支，避免任何非本人角色竄改或刪除他人尚未核准的收支申請。
     */
    private function ownsOrIsAdmin(User $user, MoneyEntry $moneyEntry): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasAnyRole([User::ROLE_MANAGER, User::ROLE_SALES])
            && $moneyEntry->created_by === $user->id;
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
