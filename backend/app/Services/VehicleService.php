<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function listVehicles(array $filters): LengthAwarePaginator
    {
        $query = Vehicle::query();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('stock_no', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('license_plate', 'like', "%{$search}%")
                    ->orWhere('vin', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 20);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createVehicle(array $data, int $userId): Vehicle
    {
        return DB::transaction(function () use ($data, $userId) {
            $vehicle = new Vehicle($data);
            $vehicle->stock_no = $this->generateStockNo();
            $vehicle->status = 'preparing';
            $vehicle->created_by = $userId;
            $vehicle->updated_by = $userId;
            $vehicle->save();

            return $vehicle;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateVehicle(Vehicle $vehicle, array $data, int $userId): Vehicle
    {
        $vehicle->fill($data);
        $vehicle->updated_by = $userId;
        $vehicle->save();

        return $vehicle;
    }

    public function deleteVehicle(Vehicle $vehicle): void
    {
        DB::transaction(function () use ($vehicle) {
            $lockedVehicle = Vehicle::query()
                ->whereKey($vehicle->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedVehicle->status !== 'preparing') {
                throw ValidationException::withMessages([
                    'status' => ['只有整備中的新建車輛可以刪除'],
                ]);
            }

            $hasMoneyEntries = MoneyEntry::query()
                ->where('vehicle_id', $lockedVehicle->id)
                ->exists();

            if ($hasMoneyEntries) {
                throw ValidationException::withMessages([
                    'status' => ['已有收支紀錄的車輛不得刪除，請改用取消/退車流程'],
                ]);
            }

            $lockedVehicle->delete();
        });
    }

    /**
     * @return array{income_total: int, expense_total: int, gross_profit: int}
     */
    public function financialSummary(Vehicle $vehicle): array
    {
        $incomeTotal = (int) MoneyEntry::query()
            ->approved()
            ->where('vehicle_id', $vehicle->id)
            ->where('direction', 'income')
            ->sum('amount');

        $expenseTotal = (int) MoneyEntry::query()
            ->approved()
            ->where('vehicle_id', $vehicle->id)
            ->where('direction', 'expense')
            ->sum('amount');

        return [
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'gross_profit' => $incomeTotal - $expenseTotal,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function listVehicle(Vehicle $vehicle, array $data, int $userId): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $userId) {
            $lockedVehicle = Vehicle::query()
                ->whereKey($vehicle->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertStatus($lockedVehicle, 'preparing', '只有整備中的車輛可以上架');

            $lockedVehicle->fill([
                'asking_price' => $data['asking_price'],
                'floor_price' => $data['floor_price'] ?? null,
                'listing_date' => $data['listing_date'] ?? now()->toDateString(),
                'sales_note' => $data['sales_note'] ?? null,
            ]);
            $lockedVehicle->status = 'listed';
            $lockedVehicle->updated_by = $userId;
            $lockedVehicle->save();

            return $lockedVehicle;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reserveVehicle(Vehicle $vehicle, array $data, int $userId): Vehicle
    {
        $idempotencyKey = (string) $data['idempotency_key'];
        $effectiveData = $this->normalizeReserveData($data);

        try {
            return DB::transaction(fn () => $this->createReservationInsideTransaction(
                $vehicle,
                $idempotencyKey,
                $effectiveData,
                $userId
            ));
        } catch (QueryException $e) {
            if (! $this->isIdempotencyKeyUniqueViolation($e)) {
                throw $e;
            }

            return $this->replayRacedReservationAfterRollback($e, $vehicle->id, $idempotencyKey, $effectiveData);
        }
    }

    /**
     * @param  array{buyer_name: string, buyer_phone: string|null, buyer_customer_id: int|null, sold_price: int, deposit_amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     */
    private function createReservationInsideTransaction(Vehicle $vehicle, string $idempotencyKey, array $effectiveData, int $userId): Vehicle
    {
        $existingEntry = MoneyEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingEntry) {
            return $this->replayOrRejectReservation($existingEntry, $vehicle->id, $effectiveData);
        }

        $lockedVehicle = Vehicle::query()
            ->whereKey($vehicle->id)
            ->lockForUpdate()
            ->firstOrFail();

        $this->assertStatus($lockedVehicle, 'listed', '只有上架中的車輛可以收訂金並保留');
        $this->assertCashAccountActive($effectiveData['cash_account_id']);

        $lockedVehicle->fill([
            'buyer_name' => $effectiveData['buyer_name'],
            'buyer_phone' => $effectiveData['buyer_phone'],
            'buyer_customer_id' => $effectiveData['buyer_customer_id'],
            'sold_price' => $effectiveData['sold_price'],
        ]);
        $lockedVehicle->status = 'reserved';
        $lockedVehicle->reserved_at = now();
        $lockedVehicle->updated_by = $userId;
        $lockedVehicle->save();

        $entry = new MoneyEntry([
            'vehicle_id' => $lockedVehicle->id,
            'cash_account_id' => $effectiveData['cash_account_id'],
            'entry_date' => $effectiveData['entry_date'],
            'direction' => 'income',
            'category' => '訂金收入',
            'amount' => $effectiveData['deposit_amount'],
            'counterparty_name' => $effectiveData['buyer_name'],
            'description' => $effectiveData['description'],
            'idempotency_key' => $idempotencyKey,
            'source_type' => MoneyEntry::SOURCE_VEHICLE_WORKFLOW,
        ]);
        $entry->created_by = $userId;
        $entry->updated_by = $userId;
        // 車輛流程收支不進審核佇列，避免車輛狀態流程被卡住。
        $entry->approval_status = MoneyEntry::APPROVAL_APPROVED;

        // Let a duplicate-key QueryException escape so DB::transaction rolls back;
        // it is caught and retried against a fresh transaction/snapshot by the caller.
        $entry->save();

        return $lockedVehicle;
    }

    /**
     * @param  array{buyer_name: string, buyer_phone: string|null, buyer_customer_id: int|null, sold_price: int, deposit_amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     */
    private function replayRacedReservationAfterRollback(QueryException $original, int $vehicleId, string $idempotencyKey, array $effectiveData): Vehicle
    {
        return DB::transaction(function () use ($original, $vehicleId, $idempotencyKey, $effectiveData) {
            // Same MySQL REPEATABLE READ stale-snapshot concern as final payment: after a
            // rollback, re-read the idempotency_key in a fresh transaction/locking read.
            $racedEntry = MoneyEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! $racedEntry) {
                throw $original;
            }

            return $this->replayOrRejectReservation($racedEntry, $vehicleId, $effectiveData);
        });
    }

    /**
     * @param  array{buyer_name: string, buyer_phone: string|null, buyer_customer_id: int|null, sold_price: int, deposit_amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     */
    private function replayOrRejectReservation(MoneyEntry $entry, int $vehicleId, array $effectiveData): Vehicle
    {
        if (! $this->isSameReservationRequest($entry, $vehicleId, $effectiveData)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['此冪等鍵已被不同保留/訂金內容使用，請重新整理後再試'],
            ]);
        }

        return Vehicle::query()->whereKey($entry->vehicle_id)->firstOrFail();
    }

    /**
     * @param  array{buyer_name: string, buyer_phone: string|null, buyer_customer_id: int|null, sold_price: int, deposit_amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     */
    private function isSameReservationRequest(MoneyEntry $entry, int $vehicleId, array $effectiveData): bool
    {
        if ((int) $entry->vehicle_id !== $vehicleId) {
            return false;
        }

        if ($entry->direction !== 'income' || $entry->category !== '訂金收入') {
            return false;
        }

        if ((int) $entry->amount !== $effectiveData['deposit_amount']) {
            return false;
        }

        if ((int) $entry->cash_account_id !== $effectiveData['cash_account_id']) {
            return false;
        }

        if ($entry->counterparty_name !== $effectiveData['buyer_name']) {
            return false;
        }

        if ($entry->description !== $effectiveData['description']) {
            return false;
        }

        if ($effectiveData['entry_date_was_supplied'] && $entry->entry_date?->toDateString() !== $effectiveData['entry_date']) {
            return false;
        }

        // Deposit entry 本身沒有記錄 sold_price/buyer_phone，但 reserve request 會把
        // 這些欄位寫進 vehicle。retry 必須確認 vehicle 上目前的值仍與 request 一致，
        // 否則會 silently replay 成功但漏掉 sold_price/buyer_phone 已被改過的事實。
        $vehicle = Vehicle::query()->whereKey($entry->vehicle_id)->first();

        if (! $vehicle) {
            return false;
        }

        if ($vehicle->buyer_name !== $effectiveData['buyer_name']) {
            return false;
        }

        if ($vehicle->buyer_phone !== $effectiveData['buyer_phone']) {
            return false;
        }

        if ((int) $vehicle->buyer_customer_id !== (int) $effectiveData['buyer_customer_id']) {
            return false;
        }

        if ((int) $vehicle->sold_price !== $effectiveData['sold_price']) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{buyer_name: string, buyer_phone: string|null, buyer_customer_id: int|null, sold_price: int, deposit_amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}
     */
    private function normalizeReserveData(array $data): array
    {
        $entryDateWasSupplied = ! empty($data['entry_date']);

        return [
            'buyer_name' => $data['buyer_name'],
            'buyer_phone' => $data['buyer_phone'] ?? null,
            'buyer_customer_id' => isset($data['buyer_customer_id']) ? (int) $data['buyer_customer_id'] : null,
            'sold_price' => (int) $data['sold_price'],
            'deposit_amount' => (int) $data['deposit_amount'],
            'cash_account_id' => (int) $data['cash_account_id'],
            'description' => $data['description'] ?? null,
            'entry_date' => $entryDateWasSupplied ? $data['entry_date'] : now()->toDateString(),
            'entry_date_was_supplied' => $entryDateWasSupplied,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{vehicle: Vehicle, warning: string|null}
     */
    public function recordFinalPayment(Vehicle $vehicle, array $data, int $userId): array
    {
        $idempotencyKey = (string) $data['idempotency_key'];
        $effectiveData = $this->normalizeFinalPaymentData($data);

        try {
            return DB::transaction(fn () => $this->createFinalPaymentInsideTransaction(
                $vehicle,
                $idempotencyKey,
                $effectiveData,
                $userId
            ));
        } catch (QueryException $e) {
            if (! $this->isIdempotencyKeyUniqueViolation($e)) {
                throw $e;
            }

            return $this->replayRacedFinalPaymentAfterRollback($e, $vehicle->id, $idempotencyKey, $effectiveData);
        }
    }

    /**
     * @param  array{amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     * @return array{vehicle: Vehicle, warning: string|null}
     */
    private function createFinalPaymentInsideTransaction(Vehicle $vehicle, string $idempotencyKey, array $effectiveData, int $userId): array
    {
        $existingEntry = MoneyEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingEntry) {
            return $this->replayOrRejectFinalPayment($existingEntry, $vehicle->id, $effectiveData);
        }

        $lockedVehicle = Vehicle::query()
            ->whereKey($vehicle->id)
            ->lockForUpdate()
            ->firstOrFail();

        $this->assertStatus($lockedVehicle, 'reserved', '只有保留中的車輛可以收尾款');
        $this->assertCashAccountActive($effectiveData['cash_account_id']);

        $entry = new MoneyEntry([
            'vehicle_id' => $lockedVehicle->id,
            'cash_account_id' => $effectiveData['cash_account_id'],
            'entry_date' => $effectiveData['entry_date'],
            'direction' => 'income',
            'category' => '尾款收入',
            'amount' => $effectiveData['amount'],
            'counterparty_name' => $lockedVehicle->buyer_name,
            'description' => $effectiveData['description'],
            'idempotency_key' => $idempotencyKey,
            'source_type' => MoneyEntry::SOURCE_VEHICLE_WORKFLOW,
        ]);
        $entry->created_by = $userId;
        $entry->updated_by = $userId;
        // 車輛流程收支不進審核佇列，避免車輛狀態流程被卡住。
        $entry->approval_status = MoneyEntry::APPROVAL_APPROVED;

        // Let a duplicate-key QueryException escape so DB::transaction rolls back;
        // it is caught and retried against a fresh transaction/snapshot by the caller.
        $entry->save();

        return [
            'vehicle' => $lockedVehicle,
            'warning' => $this->buildFinalPaymentWarning($lockedVehicle),
        ];
    }

    /**
     * @param  array{amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     * @return array{vehicle: Vehicle, warning: string|null}
     */
    private function replayRacedFinalPaymentAfterRollback(QueryException $original, int $vehicleId, string $idempotencyKey, array $effectiveData): array
    {
        return DB::transaction(function () use ($original, $vehicleId, $idempotencyKey, $effectiveData) {
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

            return $this->replayOrRejectFinalPayment($racedEntry, $vehicleId, $effectiveData);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function closeSale(Vehicle $vehicle, array $data, int $userId): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $userId) {
            $lockedVehicle = Vehicle::query()
                ->whereKey($vehicle->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertStatus($lockedVehicle, 'reserved', '只有保留中的車輛可以成交結案');

            if (! $lockedVehicle->sold_price || ! $lockedVehicle->buyer_name) {
                throw ValidationException::withMessages([
                    'sold_price' => ['成交結案前必須已有成交價與買方資料'],
                ]);
            }

            $hasIncome = MoneyEntry::query()
                ->approved()
                ->where('vehicle_id', $lockedVehicle->id)
                ->where('direction', 'income')
                ->exists();

            if (! $hasIncome) {
                throw ValidationException::withMessages([
                    'sold_price' => ['成交結案前必須至少已有一筆訂金或尾款收入'],
                ]);
            }

            $lockedVehicle->status = 'sold';
            $lockedVehicle->sold_at = $data['sold_at'] ?? now();
            $lockedVehicle->updated_by = $userId;
            $lockedVehicle->save();

            return $lockedVehicle;
        });
    }

    private function assertStatus(Vehicle $vehicle, string $expected, string $message): void
    {
        if ($vehicle->status !== $expected) {
            throw ValidationException::withMessages(['status' => [$message]]);
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

    /**
     * @param  array<string, mixed>  $data
     * @return array{amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}
     */
    private function normalizeFinalPaymentData(array $data): array
    {
        $entryDateWasSupplied = ! empty($data['entry_date']);

        return [
            'amount' => (int) $data['amount'],
            'cash_account_id' => (int) $data['cash_account_id'],
            'description' => $data['description'] ?? null,
            // Only used to populate a brand-new entry's date; retries that omit entry_date
            // are not compared against this value (see entry_date_was_supplied below).
            'entry_date' => $entryDateWasSupplied ? $data['entry_date'] : now()->toDateString(),
            'entry_date_was_supplied' => $entryDateWasSupplied,
        ];
    }

    /**
     * @param  array{amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     * @return array{vehicle: Vehicle, warning: string|null}
     */
    private function replayOrRejectFinalPayment(MoneyEntry $entry, int $vehicleId, array $effectiveData): array
    {
        if (! $this->isSameFinalPaymentRequest($entry, $vehicleId, $effectiveData)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['此冪等鍵已被不同尾款付款內容使用，請重新整理後再試'],
            ]);
        }

        $replayVehicle = Vehicle::query()->whereKey($entry->vehicle_id)->firstOrFail();

        return [
            'vehicle' => $replayVehicle,
            'warning' => $this->buildFinalPaymentWarning($replayVehicle),
        ];
    }

    /**
     * @param  array{amount: int, cash_account_id: int, description: string|null, entry_date: string, entry_date_was_supplied: bool}  $effectiveData
     */
    private function isSameFinalPaymentRequest(MoneyEntry $entry, int $vehicleId, array $effectiveData): bool
    {
        if ((int) $entry->vehicle_id !== $vehicleId) {
            return false;
        }

        if ($entry->direction !== 'income' || $entry->category !== '尾款收入') {
            return false;
        }

        if ((int) $entry->amount !== $effectiveData['amount']) {
            return false;
        }

        if ((int) $entry->cash_account_id !== $effectiveData['cash_account_id']) {
            return false;
        }

        if ($entry->description !== $effectiveData['description']) {
            return false;
        }

        // A retry that omits entry_date is a replay of "whatever date the entry was
        // originally recorded on" — it must not be forced to match "today", which would
        // make retries fail purely because they crossed midnight.
        if ($effectiveData['entry_date_was_supplied'] && $entry->entry_date?->toDateString() !== $effectiveData['entry_date']) {
            return false;
        }

        return true;
    }

    private function isIdempotencyKeyUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        if ($sqlState !== '23000') {
            return false;
        }

        return str_contains($e->getMessage(), 'idempotency_key');
    }

    private function buildFinalPaymentWarning(Vehicle $vehicle): ?string
    {
        $incomeTotal = (int) MoneyEntry::query()
            ->approved()
            ->where('vehicle_id', $vehicle->id)
            ->where('direction', 'income')
            ->sum('amount');

        if ($vehicle->sold_price !== null && $incomeTotal !== (int) $vehicle->sold_price) {
            return '訂金加尾款總額與成交價不相符，請確認金額是否正確';
        }

        return null;
    }

    /**
     * @return array{printed_at: string, vehicle: Vehicle}
     */
    public function printIntakeData(Vehicle $vehicle): array
    {
        return [
            'printed_at' => now()->toISOString(),
            'vehicle' => $vehicle,
        ];
    }

    /**
     * @return array{printed_at: string, vehicle: Vehicle, summary: array{income_total: int, expense_total: int, gross_profit: int}, money_entries: \Illuminate\Support\Collection<int, MoneyEntry>}
     */
    public function printClosingData(Vehicle $vehicle): array
    {
        $this->assertStatus($vehicle, 'sold', '只有已售出的車輛可以列印成交結案收支明細');

        $entries = MoneyEntry::query()
            ->approved()
            ->where('vehicle_id', $vehicle->id)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        return [
            'printed_at' => now()->toISOString(),
            'vehicle' => $vehicle,
            'summary' => $this->financialSummary($vehicle),
            'money_entries' => $entries,
        ];
    }

    private function generateStockNo(): string
    {
        $prefix = 'V'.now()->format('Ymd');

        $lastStockNo = Vehicle::query()
            ->where('stock_no', 'like', "{$prefix}%")
            ->lockForUpdate()
            ->orderByDesc('stock_no')
            ->value('stock_no');

        $sequence = $lastStockNo ? ((int) substr($lastStockNo, -4)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
