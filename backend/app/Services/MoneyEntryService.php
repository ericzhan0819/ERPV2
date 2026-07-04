<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\Vehicle;
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

    public static function categories(): array
    {
        return array_keys(self::CATEGORY_DIRECTIONS);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listEntries(array $filters): LengthAwarePaginator
    {
        $query = MoneyEntry::query()->with(['vehicle:id,stock_no,brand,model', 'cashAccount:id,name,type']);

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
    public function createEntry(array $data, int $userId): MoneyEntry
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
            return DB::transaction(fn () => $this->createGeneralEntryInsideTransaction($idempotencyKey, $effectiveData, $userId));
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
    private function createGeneralEntryInsideTransaction(string $idempotencyKey, array $effectiveData, int $userId): MoneyEntry
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
        $entry->created_by = $userId;
        $entry->updated_by = $userId;

        // Let a duplicate-key QueryException escape so DB::transaction rolls back;
        // it is caught and retried against a fresh transaction/snapshot by the caller.
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

            if ($lockedEntry->vehicle_id !== null) {
                $this->assertVehicleMutable((int) $lockedEntry->vehicle_id);
            }

            $lockedEntry->delete();
        });
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

        // Let a duplicate-key QueryException escape so DB::transaction rolls back;
        // it is caught and retried against a fresh transaction/snapshot by the caller.
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
            // Fresh transaction after rollback: under MySQL REPEATABLE READ, re-reading the
            // idempotency_key inside the same (now rolled-back) transaction could still see
            // the pre-race snapshot and miss the winner's row. Start a new transaction and
            // take a locking read so we observe the committed winner.
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

        // A retry that omits entry_date is a replay of "whatever date the entry was
        // originally recorded on" — it must not be forced to match "today", which would
        // make retries fail purely because they crossed midnight.
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
            ->where('cash_account_id', $cashAccount->id)
            ->where('direction', 'income')
            ->sum('amount');

        $expense = (int) MoneyEntry::query()
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
            ->whereHas('cashAccount', fn ($query) => $query->where('type', $type))
            ->where('direction', 'income')
            ->sum('amount');

        $expense = (int) MoneyEntry::query()
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
