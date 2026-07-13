<?php

namespace App\Services;

use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService
{
    // MySQL error 1451: "Cannot delete or update a parent row: a foreign key constraint fails".
    private const MYSQL_ERROR_FOREIGN_KEY_CONSTRAINT_FAILS = 1451;

    // Retried automatically by DB::transaction() on deadlock / lock wait timeout (see
    // Illuminate\Database\Concerns\ManagesTransactions::handleTransactionException()).
    // This module's writes deliberately take multiple row locks in a fixed order to
    // avoid deadlocks by construction (see lockTargetAndActiveAdmins()), but proving
    // that no ordering conflict exists against every other lock path in the app is
    // not practical to guarantee by hand-reasoning alone - this retry is the standard,
    // supported fallback for the rare deadlock that construction doesn't rule out.
    private const TRANSACTION_ATTEMPTS = 3;

    public function listUsers(): Collection
    {
        return User::query()->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createUser(array $data): User
    {
        $role = $data['role'];

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $role,
            'is_admin' => $role === User::ROLE_ADMIN,
            'is_active' => $data['is_active'] ?? true,
            'phone' => $data['phone'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'hire_date' => $data['hire_date'] ?? null,
            'notes' => $data['notes'] ?? null,
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
            'phone' => $data['phone'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'hire_date' => $data['hire_date'] ?? null,
            'notes' => $data['notes'] ?? null,
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

            if (! $isActive && $target->is_active && $target->isAdmin()) {
                $this->assertAnotherActiveAdminRemains($locked, $target);
            }

            if ($target->is_active !== $isActive) {
                $target->is_active = $isActive;
                $target->save();
            }

            return $target;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * 冪等地將使用者設為指定角色；重複呼叫相同目標角色不會有額外副作用。
     *
     * `is_admin` 於過渡期間持續與 `role` 同步（role=admin ⟷ is_admin=true），
     * 但「是否仍有啟用中的管理員」一律以 role='admin' AND is_active=true 判斷。
     */
    public function setRole(User $actingUser, User $user, string $role): User
    {
        if ($actingUser->is($user) && $role !== User::ROLE_ADMIN) {
            throw ValidationException::withMessages([
                'role' => ['不可解除自己的管理員角色'],
            ]);
        }

        return DB::transaction(function () use ($user, $role) {
            [$target, $locked] = $this->lockTargetAndActiveAdmins($user);

            if ($role !== User::ROLE_ADMIN && $target->isAdmin() && $target->is_active) {
                $this->assertAnotherActiveAdminRemains($locked, $target);
            }

            $expectedIsAdmin = $role === User::ROLE_ADMIN;

            // Always reconcile is_admin against the target role, even when role
            // itself is unchanged: a row written by older code (or otherwise left
            // desynced) could have role='manager' with a stale is_admin=true, and
            // EnsureUserIsAdmin middleware still authorizes on is_admin. Gating the
            // write on "role changed" alone would silently leave that stale grant
            // in place forever on a no-op role reassignment.
            if ($target->role !== $role || $target->is_admin !== $expectedIsAdmin) {
                $target->role = $role;
                $target->is_admin = $expectedIsAdmin;
                $target->save();
            }

            return $target;
        }, self::TRANSACTION_ATTEMPTS);
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
            // Lock any vehicles/money entries referencing this user BEFORE locking the
            // user row itself (see lockTargetAndActiveAdmins() below). Elsewhere in the
            // app, saving a vehicle/money entry update locks that child row first and
            // only then needs an implicit shared FK lock on the referenced user row
            // (to save updated_by). Locking the user row first here, as before, inverted
            // that order and could deadlock against a concurrent vehicle/money-entry
            // update: this transaction holding the user row while waiting on the child
            // row, and that one holding the child row while waiting on the user row.
            // Matching the child-then-parent order everywhere removes that cycle.
            //
            // lockForUpdate() also forces a current (non-snapshot) read here: under
            // MySQL's default REPEATABLE READ, a plain read would still see the snapshot
            // established by the first plain read in this transaction, silently missing
            // a vehicle/money entry that referenced this user and committed afterward -
            // and the delete below would then fail on the FK constraint with an
            // unhandled 500 instead of this graceful 422.
            // VehiclePhoto::withTrashed()：VehiclePhoto 有 SoftDeletes（見
            // VehiclePhotoService::deletePhoto() 的 tombstone 設計），一筆照片被刪除
            // 後，storage 清理完成前仍可能以 soft-deleted tombstone row 的型態留著，
            // 物理上仍持有指向這個使用者、ON DELETE RESTRICT 的 uploaded_by 外鍵。
            // 若這裡只查一般 scope（自動排除 soft-deleted），tombstone 存在時會誤判
            // 為「沒有相關紀錄」而放行，讓下面 delete() 才在外鍵限制上失敗（Codex
            // adversarial review 指出）。下面雖然已有 QueryException 防線把這種失敗
            // 轉成友善 422，但這裡先用 withTrashed() 照到 tombstone，能更準確、更早
            // 給出正確訊息，而不是依賴防線兜底。
            $hasRelatedRecords = Vehicle::query()
                ->where('created_by', $user->id)
                ->orWhere('updated_by', $user->id)
                ->orWhere('purchase_agent_id', $user->id)
                ->orWhere('sales_agent_id', $user->id)
                ->lockForUpdate()
                ->exists()
                || MoneyEntry::query()->where('created_by', $user->id)->orWhere('updated_by', $user->id)->lockForUpdate()->exists()
                || VehiclePhoto::withTrashed()->where('uploaded_by', $user->id)->lockForUpdate()->exists();

            if ($hasRelatedRecords) {
                throw ValidationException::withMessages([
                    'user' => ['此使用者已有相關紀錄，不得刪除，請改為停用'],
                ]);
            }

            [$target, $locked] = $this->lockTargetAndActiveAdmins($user);

            if ($target->isAdmin() && $target->is_active) {
                $this->assertAnotherActiveAdminRemains($locked, $target);
            }

            // Safety net on top of the hasRelatedRecords check above: if some
            // reference we didn't (or couldn't) account for still exists at
            // delete time, surface it as the same graceful 422 instead of
            // letting MySQL's raw FK error escape as an unhandled 500.
            try {
                $target->delete();
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === self::MYSQL_ERROR_FOREIGN_KEY_CONSTRAINT_FAILS) {
                    throw ValidationException::withMessages([
                        'user' => ['此使用者已有相關紀錄，不得刪除，請改為停用'],
                    ]);
                }

                throw $e;
            }
        }, self::TRANSACTION_ATTEMPTS);
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
                $query->where('role', User::ROLE_ADMIN)->where('is_active', true);
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
        $locked = new Collection;
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
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'user' => ['系統至少須保留一位啟用中的管理員'],
            ]);
        }
    }
}
