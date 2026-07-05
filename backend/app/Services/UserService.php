<?php

namespace App\Services;

use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
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
    public function updateUser(User $user, array $data): User
    {
        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
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

        return DB::transaction(function () use ($user, $isActive) {
            [$target, $locked] = $this->lockTargetAndActiveAdmins($user);

            if (! $isActive && $target->is_active && $target->is_admin) {
                $this->assertAnotherActiveAdminRemains($locked, $target);
            }

            if ($target->is_active !== $isActive) {
                $target->is_active = $isActive;
                $target->save();
            }

            return $target;
        });
    }

    /**
     * 冪等地將使用者設為指定的管理員權限；重複呼叫相同目標狀態不會有額外副作用。
     */
    public function setAdmin(User $actingUser, User $user, bool $isAdmin): User
    {
        if ($actingUser->is($user) && ! $isAdmin) {
            throw ValidationException::withMessages([
                'is_admin' => ['不可解除自己的管理員權限'],
            ]);
        }

        return DB::transaction(function () use ($user, $isAdmin) {
            [$target, $locked] = $this->lockTargetAndActiveAdmins($user);

            if (! $isAdmin && $target->is_admin && $target->is_active) {
                $this->assertAnotherActiveAdminRemains($locked, $target);
            }

            if ($target->is_admin !== $isAdmin) {
                $target->is_admin = $isAdmin;
                $target->save();
            }

            return $target;
        });
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

        DB::transaction(function () use ($user) {
            [$target, $locked] = $this->lockTargetAndActiveAdmins($user);

            if ($target->is_admin && $target->is_active) {
                $this->assertAnotherActiveAdminRemains($locked, $target);
            }

            $hasRelatedRecords = Vehicle::query()->where('created_by', $target->id)->orWhere('updated_by', $target->id)->exists()
                || MoneyEntry::query()->where('created_by', $target->id)->orWhere('updated_by', $target->id)->exists();

            if ($hasRelatedRecords) {
                throw ValidationException::withMessages([
                    'user' => ['此使用者已有相關紀錄，不得刪除，請改為停用'],
                ]);
            }

            $target->delete();
        });
    }

    /**
     * 鎖定目標使用者列與所有啟用中的管理員列，並以固定順序（id 遞增）逐一鎖定。
     *
     * 兩個並發請求各自互相操作對方帳號時（例如管理員 A 降級 B、同時 B 降級 A），
     * 若兩個 transaction 鎖定同一批列的順序相反，就會形成 deadlock。
     *
     * 這裡刻意不用「單一個 SELECT ... FOR UPDATE ORDER BY id」一次鎖定整批列：
     * 該寫法的鎖定順序取決於 MySQL optimizer 實際選擇的存取路徑（是否走 PK、
     * 是否用 index merge／filesort 處理 OR 條件等），而這是未公開保證、可能隨資料
     * 分佈或版本改變的實作細節，不能拿來當作避免 deadlock 的依據。
     * 改成應用層自行控制順序：先不鎖地讀出候選 id 清單，於 PHP 端依 id 遞增排序，
     * 再逐一各自發送一次 SELECT ... FOR UPDATE 取得鎖。只要每個 transaction 都用
     * 同一種全域遞增順序鎖定它所需要的列（即使候選清單彼此不完全相同），任兩個
     * transaction 對共同列的鎖定順序就必然一致，deadlock 便無法形成。
     *
     * @return array{0: User, 1: Collection<int, User>}
     */
    private function lockTargetAndActiveAdmins(User $user): array
    {
        $candidateIds = User::query()
            ->where('id', $user->id)
            ->orWhere(function ($query) {
                $query->where('is_admin', true)->where('is_active', true);
            })
            ->pluck('id')
            ->push($user->id)
            ->unique()
            ->sort()
            ->values();

        // A non-target candidate (an admin snapshotted before locking) may have
        // been deleted by a concurrent request by the time we reach it here;
        // that's fine to skip, since a row that no longer exists can't count
        // toward "remaining admins" either way. Only the target row missing is
        // an actual error for this operation.
        $locked = new Collection();
        foreach ($candidateIds as $id) {
            $lockedUser = User::query()->lockForUpdate()->find($id);

            if ($lockedUser) {
                $locked->push($lockedUser);
            } elseif ($id === $user->id) {
                throw (new ModelNotFoundException)->setModel(User::class, [$id]);
            }
        }

        $target = $locked->firstWhere('id', $user->id);

        return [$target, $locked];
    }

    /**
     * 確保排除 $target 之後，已鎖定的集合中至少還有一位啟用中的管理員。
     * $locked 必須是 lockTargetAndActiveAdmins() 回傳、已在同一 transaction 內鎖定的集合，
     * 才能保證這裡看到的管理員名單不會被其他並發 transaction 同時修改。
     */
    private function assertAnotherActiveAdminRemains(Collection $locked, User $target): void
    {
        $remaining = $locked
            ->where('id', '!=', $target->id)
            ->where('is_admin', true)
            ->where('is_active', true)
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'user' => ['系統至少須保留一位啟用中的管理員'],
            ]);
        }
    }
}
