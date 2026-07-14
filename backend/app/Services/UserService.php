<?php

namespace App\Services;

use App\Models\CommissionPlan;
use App\Models\MoneyEntry;
use App\Models\SalaryPeriod;
use App\Models\SalaryProfile;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
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
    // MySQL 錯誤 1451：無法刪除或更新父資料列，因為外鍵限制失敗。
    private const MYSQL_ERROR_FOREIGN_KEY_CONSTRAINT_FAILS = 1451;

    // 遇到死鎖或等鎖逾時時，DB::transaction() 會自動重試。本模組以固定順序鎖多筆資料來
    // 避免死鎖，但無法僅靠人工推論保證不會與系統其他鎖定路徑衝突；這是少數情況的標準備援。
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

            // 即使角色未變，也要讓 is_admin 與目標角色一致。舊程式可能留下 role=manager、
            // is_admin=true 的不同步資料，而權限中介層仍會看 is_admin。若只在角色改變時更新，
            // 重新指定相同角色也無法清掉這個過期權限。
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
            // 先鎖住所有參照此使用者的車輛與收支，再鎖使用者本身。系統其他地方儲存車輛或收支時，
            // 也會先鎖子資料，再因更新 updated_by 對使用者取得共享外鍵鎖。若這裡反過來先鎖使用者，
            // 就可能與並行更新互相等待而死鎖；全系統維持子資料再父資料的順序即可避免此循環。
            //
            // lockForUpdate() 也會強制讀取目前資料，而不是舊快照。MySQL 預設 REPEATABLE READ
            // 下，一般查詢可能漏掉交易開始後才提交、但已參照此使用者的車輛或收支，讓刪除操作
            // 最後撞上外鍵限制並變成未處理的 500，而不是這裡預期回傳的 422。
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
                || VehiclePhoto::withTrashed()->where('uploaded_by', $user->id)->lockForUpdate()->exists()
                || SalaryProfile::query()->where('user_id', $user->id)->lockForUpdate()->exists()
                || CommissionPlan::query()->where('created_by', $user->id)->lockForUpdate()->exists()
                || SalaryPeriod::query()
                    ->where('created_by', $user->id)
                    ->orWhere('confirmed_by', $user->id)
                    ->orWhere('paid_by', $user->id)
                    ->lockForUpdate()
                    ->exists()
                || SalarySettlement::query()->where('user_id', $user->id)->lockForUpdate()->exists()
                || SalarySettlementItem::query()->where('created_by', $user->id)->lockForUpdate()->exists();

            if ($hasRelatedRecords) {
                throw ValidationException::withMessages([
                    'user' => ['此使用者已有相關紀錄，不得刪除，請改為停用'],
                ]);
            }

            [$target, $locked] = $this->lockTargetAndActiveAdmins($user);

            if ($target->isAdmin() && $target->is_active) {
                $this->assertAnotherActiveAdminRemains($locked, $target);
            }

            // 作為 hasRelatedRecords 檢查外的最後防線：若刪除時仍有未涵蓋的關聯資料，
            // 回傳相同的明確 422，而不是讓 MySQL 原始外鍵錯誤變成未處理的 500。
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
     * 交易對共同資料列的鎖定順序就必然一致，死鎖便無法形成。
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

        // 非目標的候選管理員，可能在取得快照後被並行請求刪除；可略過，因為不存在的資料列
        // 本來就不算剩餘管理員。只有目標使用者不存在才是這個操作的真正錯誤。
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
