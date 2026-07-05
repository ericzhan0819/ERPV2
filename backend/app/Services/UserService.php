<?php

namespace App\Services;

use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function listUsers(): Collection
    {
        return User::query()->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createUser(array $data): User
    {
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => $data['is_admin'] ?? false,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $user->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateUser(User $actingUser, User $user, array $data): User
    {
        if ($actingUser->is($user) && ! $data['is_admin']) {
            throw ValidationException::withMessages([
                'is_admin' => ['不可解除自己的管理員權限'],
            ]);
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_admin' => $data['is_admin'],
        ]);
        $user->save();

        return $user;
    }

    /**
     * 冪等地將使用者設為指定的啟用狀態；重複呼叫相同目標狀態不會有額外副作用。
     */
    public function setActive(User $actingUser, User $user, bool $isActive): User
    {
        if ($actingUser->is($user) && ! $isActive) {
            throw ValidationException::withMessages([
                'is_active' => ['不可停用自己的帳號'],
            ]);
        }

        if ($user->is_active !== $isActive) {
            $user->is_active = $isActive;
            $user->save();
        }

        return $user;
    }

    public function resetPassword(User $user, string $password): User
    {
        $user->password = Hash::make($password);
        $user->save();

        return $user;
    }

    public function deleteUser(User $actingUser, User $user): void
    {
        if ($actingUser->is($user)) {
            throw ValidationException::withMessages([
                'user' => ['不可刪除自己的帳號'],
            ]);
        }

        $hasRelatedRecords = Vehicle::query()->where('created_by', $user->id)->orWhere('updated_by', $user->id)->exists()
            || MoneyEntry::query()->where('created_by', $user->id)->orWhere('updated_by', $user->id)->exists();

        if ($hasRelatedRecords) {
            throw ValidationException::withMessages([
                'user' => ['此使用者已有相關紀錄，不得刪除，請改為停用'],
            ]);
        }

        $user->delete();
    }
}
