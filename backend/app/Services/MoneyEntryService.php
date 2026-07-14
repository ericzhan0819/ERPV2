<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\VehicleMoneyCategories;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MoneyEntryService
{
    /**
     * 分類與收入/支出方向的對應，用來擋 category 與 direction 不一致的錯誤輸入。
     */
    private const CATEGORY_DIRECTIONS = [
        '訂金收入' => 'income',
        '尾款收入' => 'income',
        '其他單車收入' => 'income',
        '一般收入' => 'income',
        '購車付款' => 'expense',
        '維修支出' => 'expense',
        '美容支出' => 'expense',
        '代辦支出' => 'expense',
        '拍場支出' => 'expense',
        '退款' => 'expense',
        '租金' => 'expense',
        '水電' => 'expense',
        '廣告' => 'expense',
        '平台費' => 'expense',
        '薪資 / 佣金' => 'expense',
        '稅金支出' => 'expense',
        '其他支出' => 'expense',
    ];

    /**
     * 與車輛有關的收支，必須綁定 vehicle_id。
     */
    private const VEHICLE_REQUIRED_CATEGORIES = [
        '訂金收入', '尾款收入', '其他單車收入', '購車付款',
        '維修支出', '美容支出', '代辦支出', '拍場支出', '退款',
    ];

    /**
     * 一般營運收支，不得綁定 vehicle_id，避免污染單車毛利。
     */
    private const GENERAL_ONLY_CATEGORIES = [
        '一般收入', '租金', '水電', '廣告', '平台費', '薪資 / 佣金', '稅金支出',
    ];

    /**
     * 已售出/已取消的車輛不得再新增或修改綁定的收支。
     */
    private const LOCKED_VEHICLE_STATUSES = ['sold', 'cancelled'];

    /**
     * 老闆身兼會計：只有 admin 建立的收支（不論 manual/vehicle_shortcut/vehicle_workflow）
     * 才直接 approved，manager/sales 建立一律 pending，待 admin 核准後才計入正式餘額。
     */
    public const APPROVABLE_SOURCE_TYPES = [
        MoneyEntry::SOURCE_MANUAL,
        MoneyEntry::SOURCE_VEHICLE_SHORTCUT,
        MoneyEntry::SOURCE_VEHICLE_WORKFLOW,
    ];

    public static function categories(): array
    {
        return array_keys(self::CATEGORY_DIRECTIONS);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listEntries(array $filters, ?User $user = null): LengthAwarePaginator
    {
        $query = MoneyEntry::query()->with(['vehicle:id,stock_no,brand,model', 'cashAccount:id,name,type']);

        // 薪資支出雖然也是 MoneyEntry，counterparty_name 與 amount 足以反推出個人
        // 薪資，初版只能由 admin 查看。不能只在 Resource 隱藏欄位，否則 manager
        // 仍可從筆數、分類、日期等側面枚舉薪資紀錄。
        if (! ($user?->isAdmin() ?? false)) {
            $query->where('source_type', '!=', MoneyEntry::SOURCE_SALARY_SETTLEMENT);
        }

        // sales 不應在收支列表看到全公司所有成本紀錄的分類、對象、描述：只能看到
        // 自己建立的收支申請，或訂金/尾款/退款等銷售收款安全紀錄（不論由誰建立）。
        if ($user?->isSales()) {
            $query->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                    ->orWhereIn('category', VehicleMoneyCategories::SALES_SAFE);
            });
        }

        if (! empty($filters['vehicle_id'])) {
            $query->where('vehicle_id', $filters['vehicle_id']);
        }

        if (! empty($filters['cash_account_id'])) {
            $query->where('cash_account_id', $filters['cash_account_id']);
        }

        if (! empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['approval_status'])) {
            $query->where('approval_status', $filters['approval_status']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('entry_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('entry_date', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('counterparty_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createEntry(array $data, User $user): MoneyEntry
    {
        $idempotencyKey = (string) $data['idempotency_key'];
        $effectiveData = [
            'vehicle_id' => isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
            'cash_account_id' => (int) $data['cash_account_id'],
            'entry_date' => $data['entry_date'],
            'direction' => $data['direction'],
            'category' => $data['category'],
            'amount' => (int) $data['amount'],
            'counterparty_name' => $data['counterparty_name'] ?? null,
            'description' => $data['description'] ?? null,
        ];

        try {
            return DB::transaction(fn () => $this->createGeneralEntryInsideTransaction($idempotencyKey, $effectiveData, $user));
        } catch (QueryException $e) {
            if (! $this->isIdempotencyKeyUniqueViolation($e)) {
                throw $e;
            }

            return $this->replayRacedEntryAfterRollback($e, $idempotencyKey, $effectiveData, true);
        }
    }

    /**
     * @param  array{vehicle_id: int|null, cash_account_id: int, entry_date: string, direction: string, category: string, amount: int, counterparty_name: string|null, description: string|null}  $effectiveData
     */
    private function createGeneralEntryInsideTransaction(string $idempotencyKey, array $effectiveData, User $user): MoneyEntry
    {
        $existingEntry = MoneyEntry::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existingEntry) {
            return $this->replayOrRejectEntry($existingEntry, $effectiveData, true);
        }

        if ($effectiveData['vehicle_id'] !== null) {
            $this->assertVehicleMutable($effectiveData['vehicle_id']);
        }

        $this->assertCashAccountActive($effectiveData['cash_account_id']);
        $this->assertCategoryRules($effectiveData['category'], $effectiveData['direction'], $effectiveData['vehicle_id']);

        $entry = new MoneyEntry([
            'vehicle_id' => $effectiveData['vehicle_id'],
            'cash_account_id' => $effectiveData['cash_account_id'],
            'entry_date' => $effectiveData['entry_date'],
            'direction' => $effectiveData['direction'],
            'category' => $effectiveData['category'],
            'amount' => $effectiveData['amount'],
            'counterparty_name' => $effectiveData['counterparty_name'],
            'description' => $effectiveData['description'],
            'idempotency_key' => $idempotencyKey,
            'source_type' => MoneyEntry::SOURCE_MANUAL,
        ]);
        $entry->created_by = $user->id;
        $entry->updated_by = $user->id;
        // 一般收支審核邊界：只套用於 manual 收支。admin 建立直接 approved，
        // manager/sales 建立進 pending，待 admin 核准後才計入正式餘額。
        $entry->approval_status = $user->isAdmin() ? MoneyEntry::APPROVAL_APPROVED : MoneyEntry::APPROVAL_PENDING;

        // 讓重複鍵例外離開，DB::transaction 才會回滾；呼叫端會在新的交易與快照中捕捉並重試。
        $entry->save();

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateEntry(MoneyEntry $entry, array $data, int $userId): MoneyEntry
    {
        return DB::transaction(function () use ($entry, $data, $userId) {
            $lockedEntry = MoneyEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($lockedEntry->source_type !== MoneyEntry::SOURCE_MANUAL) {
                throw ValidationException::withMessages([
                    'source_type' => ['流程、快捷或來源未確認的既有收支不得透過一般收支功能修改'],
                ]);
            }

            if ($lockedEntry->approval_status !== MoneyEntry::APPROVAL_PENDING) {
                throw ValidationException::withMessages([
                    'approval_status' => ['已核准或已駁回的收支不得修改，如需修正請新增一筆收支'],
                ]);
            }

            if ($lockedEntry->vehicle_id !== null) {
                $this->assertVehicleMutable((int) $lockedEntry->vehicle_id);
            }

            $newVehicleId = isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null;
            if ($newVehicleId !== null && $newVehicleId !== (int) $lockedEntry->vehicle_id) {
                $this->assertVehicleMutable($newVehicleId);
            }

            $this->assertCashAccountActive((int) $data['cash_account_id']);
            $this->assertCategoryRules($data['category'], $data['direction'], $data['vehicle_id'] ?? null);

            $lockedEntry->fill($data);
            $lockedEntry->updated_by = $userId;
            $lockedEntry->save();

            return $lockedEntry;
        });
    }

    public function deleteEntry(MoneyEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $lockedEntry = MoneyEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($lockedEntry->source_type !== MoneyEntry::SOURCE_MANUAL) {
                throw ValidationException::withMessages([
                    'source_type' => ['流程、快捷或來源未確認的既有收支不得透過一般收支功能刪除'],
                ]);
            }

            if ($lockedEntry->approval_status !== MoneyEntry::APPROVAL_PENDING) {
                throw ValidationException::withMessages([
                    'approval_status' => ['已核准或已駁回的收支不得刪除，如需修正請新增一筆收支'],
                ]);
            }

            if ($lockedEntry->vehicle_id !== null) {
                $this->assertVehicleMutable((int) $lockedEntry->vehicle_id);
            }

            $lockedEntry->delete();
        });
    }

    public function approve(MoneyEntry $entry, int $approverId): MoneyEntry
    {
        return DB::transaction(function () use ($entry, $approverId) {
            $lockedEntry = MoneyEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            $this->assertPendingApprovableEntry($lockedEntry, '核准');
            $this->lockVehicleForApprovalAndAssertCollectionInvariant($lockedEntry);

            $lockedEntry->approval_status = MoneyEntry::APPROVAL_APPROVED;
            $lockedEntry->approved_by = $approverId;
            $lockedEntry->approved_at = now();
            $lockedEntry->save();

            return $lockedEntry;
        });
    }

    public function reject(MoneyEntry $entry, int $approverId): MoneyEntry
    {
        return DB::transaction(function () use ($entry, $approverId) {
            $lockedEntry = MoneyEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            $this->assertPendingApprovableEntry($lockedEntry, '駁回');

            $lockedEntry->approval_status = MoneyEntry::APPROVAL_REJECTED;
            $lockedEntry->approved_by = $approverId;
            $lockedEntry->approved_at = now();
            $lockedEntry->save();

            return $lockedEntry;
        });
    }

    /**
     * 老闆身兼會計：manual、vehicle_shortcut、vehicle_workflow 建立時若非 admin 一律
     * pending，因此這三種來源都可能出現待核准的收支，admin 都必須能核准/駁回；
     * 只有 legacy_unknown（來源未確認的既有資料）不可核准/駁回，需人工確認後改派
     * 正確的 source_type。
     */
    private function assertPendingApprovableEntry(MoneyEntry $entry, string $action): void
    {
        if (! in_array($entry->source_type, self::APPROVABLE_SOURCE_TYPES, true)) {
            throw ValidationException::withMessages([
                'source_type' => ["來源未確認的既有收支不可{$action}，需人工確認來源後再處理"],
            ]);
        }

        if ($entry->approval_status !== MoneyEntry::APPROVAL_PENDING) {
            throw ValidationException::withMessages([
                'approval_status' => ["只有待審核的收支可以{$action}，狀態不可逆"],
            ]);
        }
    }

    /**
     * VehicleService::closeSale() 只在「結案當下」擋掉待審訂金/尾款/退款，防止結案後才
     * 核准這類紀錄、事後改變已核准淨收款。但這個保護只涵蓋「未來」的結案動作：本次修正
     * 部署前就已經結案、且當時仍留有待審訂金/尾款/退款的既有車輛，不會重新觸發那個檢查，
     * approve() 若不獨立檢查，仍可能核准這類紀錄、把已售出車輛的已核准淨收款拉到成交價
     * 以下，讓「已關帳＝已收足額」這個不變量在事後被打破。因此這裡直接鎖定車輛列（與
     * closeSale() 使用同一把鎖，避免與結案動作競態）並拒絕核准，不論車輛是何時、以何種
     * 方式進入 sold/cancelled 狀態。
     */
    private function lockVehicleForApprovalAndAssertCollectionInvariant(MoneyEntry $entry): void
    {
        if ($entry->vehicle_id === null) {
            return;
        }

        // 所有綁車收支核准都鎖定同一台車。薪資月份確認會先鎖候選車，再重跑資格檢查；
        // 這裡共用車輛列鎖後，維修等非銷售類 pending 支出也不能在確認途中核准，
        // 避免已確認獎金使用過時毛利。核准仍先鎖 MoneyEntry，再等待車輛，不會讓確認
        // 流程反向等待 MoneyEntry，因為資格檢查只讀收支、不取得收支列鎖。
        $vehicleStatus = Vehicle::query()
            ->whereKey($entry->vehicle_id)
            ->lockForUpdate()
            ->value('status');

        if (in_array($entry->category, VehicleMoneyCategories::SALES_SAFE, true)
            && in_array($vehicleStatus, ['sold', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['車輛已成交結案或取消，此筆訂金/尾款/退款不可再核准，如需修正請聯絡系統管理員手動處理'],
            ]);
        }
    }

    private function isCreatorAdmin(int $userId): bool
    {
        // role 是正式權限來源（見 CLAUDE.md 過渡期規則），不用 is_admin 判斷。
        return User::query()->whereKey($userId)->value('role') === User::ROLE_ADMIN;
    }

    /**
     * 車輛快捷收支（購車付款／單車支出／收訂金／退款）共用的建立邏輯。
     *
     * @param  array<string, mixed>  $data
     */
    public function recordVehicleShortcut(Vehicle $vehicle, string $direction, string $category, array $data, int $userId): MoneyEntry
    {
        $idempotencyKey = (string) $data['idempotency_key'];
        $entryDateWasSupplied = ! empty($data['entry_date']);
        $effectiveData = [
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => (int) $data['cash_account_id'],
            'entry_date' => $entryDateWasSupplied ? $data['entry_date'] : now()->toDateString(),
            'direction' => $direction,
            'category' => $category,
            'amount' => (int) $data['amount'],
            'counterparty_name' => $data['counterparty_name'] ?? null,
            'description' => $data['description'] ?? null,
        ];

        try {
            return DB::transaction(fn () => $this->createShortcutEntryInsideTransaction($idempotencyKey, $effectiveData, $userId, $entryDateWasSupplied));
        } catch (QueryException $e) {
            if (! $this->isIdempotencyKeyUniqueViolation($e)) {
                throw $e;
            }

            return $this->replayRacedEntryAfterRollback($e, $idempotencyKey, $effectiveData, $entryDateWasSupplied);
        }
    }

    /**
     * @param  array{vehicle_id: int, cash_account_id: int, entry_date: string, direction: string, category: string, amount: int, counterparty_name: string|null, description: string|null}  $effectiveData
     */
    private function createShortcutEntryInsideTransaction(string $idempotencyKey, array $effectiveData, int $userId, bool $entryDateWasSupplied): MoneyEntry
    {
        $existingEntry = MoneyEntry::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existingEntry) {
            return $this->replayOrRejectEntry($existingEntry, $effectiveData, $entryDateWasSupplied);
        }

        $this->assertVehicleMutable($effectiveData['vehicle_id']);
        $this->assertCashAccountActive($effectiveData['cash_account_id']);

        $entry = new MoneyEntry([
            'vehicle_id' => $effectiveData['vehicle_id'],
            'cash_account_id' => $effectiveData['cash_account_id'],
            'entry_date' => $effectiveData['entry_date'],
            'direction' => $effectiveData['direction'],
            'category' => $effectiveData['category'],
            'amount' => $effectiveData['amount'],
            'counterparty_name' => $effectiveData['counterparty_name'],
            'description' => $effectiveData['description'],
            'idempotency_key' => $idempotencyKey,
            'source_type' => MoneyEntry::SOURCE_VEHICLE_SHORTCUT,
        ]);
        $entry->created_by = $userId;
        $entry->updated_by = $userId;
        // 老闆身兼會計：只有 admin 建立才直接 approved，manager/sales 上報的車輛快捷
        // 收支（購車付款／單車支出／收訂金／退款）一律進 pending，待 admin 核准。
        $entry->approval_status = $this->isCreatorAdmin($userId) ? MoneyEntry::APPROVAL_APPROVED : MoneyEntry::APPROVAL_PENDING;

        // 讓重複鍵例外離開，DB::transaction 才會回滾；呼叫端會在新的交易與快照中捕捉並重試。
        $entry->save();

        return $entry;
    }

    /**
     * @param  array{vehicle_id: int|null, cash_account_id: int, entry_date: string, direction: string, category: string, amount: int, counterparty_name: string|null, description: string|null}  $effectiveData
     */
    private function replayOrRejectEntry(MoneyEntry $entry, array $effectiveData, bool $entryDateWasSupplied): MoneyEntry
    {
        if (! $this->isSameEntryRequest($entry, $effectiveData, $entryDateWasSupplied)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['此冪等鍵已被不同收支內容使用，請重新整理後再試'],
            ]);
        }

        return $entry;
    }

    /**
     * @param  array{vehicle_id: int|null, cash_account_id: int, entry_date: string, direction: string, category: string, amount: int, counterparty_name: string|null, description: string|null}  $effectiveData
     */
    private function replayRacedEntryAfterRollback(QueryException $original, string $idempotencyKey, array $effectiveData, bool $entryDateWasSupplied): MoneyEntry
    {
        return DB::transaction(function () use ($original, $idempotencyKey, $effectiveData, $entryDateWasSupplied) {
            // 回滾後要開新交易：MySQL 的 REPEATABLE READ 在原交易重讀時，仍可能看到競態前的快照，
            // 因而漏掉已提交的勝出資料。改用加鎖讀取，才能看到該筆資料。
            $racedEntry = MoneyEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! $racedEntry) {
                throw $original;
            }

            return $this->replayOrRejectEntry($racedEntry, $effectiveData, $entryDateWasSupplied);
        });
    }

    /**
     * @param  array{vehicle_id: int|null, cash_account_id: int, entry_date: string, direction: string, category: string, amount: int, counterparty_name: string|null, description: string|null}  $effectiveData
     */
    private function isSameEntryRequest(MoneyEntry $entry, array $effectiveData, bool $entryDateWasSupplied): bool
    {
        $entryVehicleId = $entry->vehicle_id !== null ? (int) $entry->vehicle_id : null;

        if ($entryVehicleId !== $effectiveData['vehicle_id']) {
            return false;
        }

        if ((int) $entry->cash_account_id !== $effectiveData['cash_account_id']) {
            return false;
        }

        if ($entry->direction !== $effectiveData['direction'] || $entry->category !== $effectiveData['category']) {
            return false;
        }

        if ((int) $entry->amount !== $effectiveData['amount']) {
            return false;
        }

        if ($entry->counterparty_name !== $effectiveData['counterparty_name']) {
            return false;
        }

        if ($entry->description !== $effectiveData['description']) {
            return false;
        }

        // 重試未帶 entry_date 時，表示沿用原本記錄的日期；不可強迫它符合「今天」，
        // 否則只因跨過午夜就會被誤判為不同請求。
        if ($entryDateWasSupplied && $entry->entry_date?->toDateString() !== $effectiveData['entry_date']) {
            return false;
        }

        return true;
    }

    /**
     * 帳戶目前餘額 = 期初餘額 + 收入總額 - 支出總額，供 Dashboard/CashAccount/Vehicle 共用。
     */
    public function balanceForAccount(CashAccount $cashAccount): int
    {
        $income = (int) MoneyEntry::query()
            ->approved()
            ->where('cash_account_id', $cashAccount->id)
            ->where('direction', 'income')
            ->sum('amount');

        $expense = (int) MoneyEntry::query()
            ->approved()
            ->where('cash_account_id', $cashAccount->id)
            ->where('direction', 'expense')
            ->sum('amount');

        return (int) $cashAccount->opening_balance + $income - $expense;
    }

    /**
     * 依帳戶類型（現金／銀行／其他）加總所有同類型帳戶的目前餘額，供 Dashboard 卡片使用。
     */
    public function balanceForType(string $type): int
    {
        $openingBalance = (int) CashAccount::query()->where('type', $type)->sum('opening_balance');

        $income = (int) MoneyEntry::query()
            ->approved()
            ->whereHas('cashAccount', fn ($query) => $query->where('type', $type))
            ->where('direction', 'income')
            ->sum('amount');

        $expense = (int) MoneyEntry::query()
            ->approved()
            ->whereHas('cashAccount', fn ($query) => $query->where('type', $type))
            ->where('direction', 'expense')
            ->sum('amount');

        return $openingBalance + $income - $expense;
    }

    private function assertVehicleMutable(int $vehicleId): void
    {
        $status = Vehicle::query()
            ->whereKey($vehicleId)
            ->lockForUpdate()
            ->value('status');

        if ($status === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['找不到指定的車輛'],
            ]);
        }

        if (in_array($status, self::LOCKED_VEHICLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['已售出或已取消的車輛不得新增/修改/刪除收支'],
            ]);
        }
    }

    private function assertCashAccountActive(int $cashAccountId): void
    {
        $isActive = CashAccount::query()
            ->whereKey($cashAccountId)
            ->lockForUpdate()
            ->value('is_active');

        if (! $isActive) {
            throw ValidationException::withMessages([
                'cash_account_id' => ['停用帳戶不得新增收支'],
            ]);
        }
    }

    private function assertCategoryRules(string $category, string $direction, ?int $vehicleId): void
    {
        $expectedDirection = self::CATEGORY_DIRECTIONS[$category] ?? null;

        if ($expectedDirection !== null && $expectedDirection !== $direction) {
            throw ValidationException::withMessages([
                'category' => ["分類「{$category}」與收入/支出方向不符"],
            ]);
        }

        if (in_array($category, self::VEHICLE_REQUIRED_CATEGORIES, true) && $vehicleId === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['此分類為單車收支，必須綁定車輛'],
            ]);
        }

        if (in_array($category, self::GENERAL_ONLY_CATEGORIES, true) && $vehicleId !== null) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['一般營運收支不得綁定車輛'],
            ]);
        }
    }

    private function isIdempotencyKeyUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        if ($sqlState !== '23000') {
            return false;
        }

        return str_contains($e->getMessage(), 'idempotency_key');
    }
}
