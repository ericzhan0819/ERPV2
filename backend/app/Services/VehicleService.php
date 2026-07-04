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
            ->where('vehicle_id', $vehicle->id)
            ->where('direction', 'income')
            ->sum('amount');

        $expenseTotal = (int) MoneyEntry::query()
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
        return DB::transaction(function () use ($vehicle, $data, $userId) {
            $lockedVehicle = Vehicle::query()
                ->whereKey($vehicle->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertStatus($lockedVehicle, 'listed', '只有上架中的車輛可以收訂金並保留');
            $this->assertCashAccountActive((int) $data['cash_account_id']);

            $lockedVehicle->fill([
                'buyer_name' => $data['buyer_name'],
                'buyer_phone' => $data['buyer_phone'] ?? null,
                'sold_price' => $data['sold_price'],
            ]);
            $lockedVehicle->status = 'reserved';
            $lockedVehicle->reserved_at = now();
            $lockedVehicle->updated_by = $userId;
            $lockedVehicle->save();

            $entry = new MoneyEntry([
                'vehicle_id' => $lockedVehicle->id,
                'cash_account_id' => $data['cash_account_id'],
                'entry_date' => $data['entry_date'] ?? now()->toDateString(),
                'direction' => 'income',
                'category' => '訂金收入',
                'amount' => $data['deposit_amount'],
                'counterparty_name' => $data['buyer_name'],
                'description' => $data['description'] ?? null,
            ]);
            $entry->created_by = $userId;
            $entry->updated_by = $userId;
            $entry->save();

            return $lockedVehicle;
        });
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
        ]);
        $entry->created_by = $userId;
        $entry->updated_by = $userId;

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
            ->where('vehicle_id', $vehicle->id)
            ->where('direction', 'income')
            ->sum('amount');

        if ($vehicle->sold_price !== null && $incomeTotal !== (int) $vehicle->sold_price) {
            return '訂金加尾款總額與成交價不相符，請確認金額是否正確';
        }

        return null;
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
