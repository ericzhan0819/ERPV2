<?php

namespace App\Policies;

use App\Models\MoneyEntry;
use App\Models\User;
use App\Services\MoneyEntryService;

class MoneyEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_SALES]);
    }

    /**
     * 單筆查詢必須套用與列表（MoneyEntryService::listEntries()）相同的範圍限制，
     * 否則 sales 雖然在列表看不到別人上報的成本紀錄，仍可用連號 id 直接打
     * GET /api/money-entries/{id} 逐一枚舉出這些原本應被遮蔽的紀錄（分類、對象、
     * 描述等 MoneyEntryResource 不會遮蔽的欄位）。
     */
    public function view(User $user, MoneyEntry $moneyEntry): bool
    {
        if ($user->hasAnyRole([User::ROLE_ADMIN, User::ROLE_MANAGER])) {
            return true;
        }

        if ($user->isSales()) {
            return $moneyEntry->created_by === $user->id
                || in_array($moneyEntry->category, MoneyEntryService::SALES_SAFE_COLLECTION_CATEGORIES, true);
        }

        return false;
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
