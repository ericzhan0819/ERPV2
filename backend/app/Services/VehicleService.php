<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        $vehicle->delete();
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
        $this->assertStatus($vehicle, 'preparing', '只有整備中的車輛可以上架');

        return DB::transaction(function () use ($vehicle, $data, $userId) {
            $vehicle->fill([
                'asking_price' => $data['asking_price'],
                'floor_price' => $data['floor_price'] ?? null,
                'listing_date' => $data['listing_date'] ?? now()->toDateString(),
                'sales_note' => $data['sales_note'] ?? null,
            ]);
            $vehicle->status = 'listed';
            $vehicle->updated_by = $userId;
            $vehicle->save();

            return $vehicle;
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
        return DB::transaction(function () use ($vehicle, $data, $userId) {
            $idempotencyKey = (string) $data['idempotency_key'];
            $existingEntry = MoneyEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingEntry) {
                if (! $this->isSameFinalPaymentRequest($existingEntry, $vehicle->id, $data)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['此冪等鍵已被不同尾款付款內容使用，請重新整理後再試'],
                    ]);
                }

                $replayVehicle = Vehicle::query()->whereKey($existingEntry->vehicle_id)->firstOrFail();

                return [
                    'vehicle' => $replayVehicle,
                    'warning' => $this->buildFinalPaymentWarning($replayVehicle),
                ];
            }

            $lockedVehicle = Vehicle::query()
                ->whereKey($vehicle->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertStatus($lockedVehicle, 'reserved', '只有保留中的車輛可以收尾款');
            $this->assertCashAccountActive((int) $data['cash_account_id']);

            $entry = new MoneyEntry([
                'vehicle_id' => $lockedVehicle->id,
                'cash_account_id' => $data['cash_account_id'],
                'entry_date' => $data['entry_date'] ?? now()->toDateString(),
                'direction' => 'income',
                'category' => '尾款收入',
                'amount' => $data['amount'],
                'counterparty_name' => $lockedVehicle->buyer_name,
                'description' => $data['description'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);
            $entry->created_by = $userId;
            $entry->updated_by = $userId;
            $entry->save();

            return [
                'vehicle' => $lockedVehicle,
                'warning' => $this->buildFinalPaymentWarning($lockedVehicle),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function closeSale(Vehicle $vehicle, array $data, int $userId): Vehicle
    {
        $this->assertStatus($vehicle, 'reserved', '只有保留中的車輛可以成交結案');

        if (! $vehicle->sold_price || ! $vehicle->buyer_name) {
            throw ValidationException::withMessages([
                'sold_price' => ['成交結案前必須已有成交價與買方資料'],
            ]);
        }

        $hasIncome = MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('direction', 'income')
            ->exists();

        if (! $hasIncome) {
            throw ValidationException::withMessages([
                'sold_price' => ['成交結案前必須至少已有一筆訂金或尾款收入'],
            ]);
        }

        return DB::transaction(function () use ($vehicle, $data, $userId) {
            $vehicle->status = 'sold';
            $vehicle->sold_at = $data['sold_at'] ?? now();
            $vehicle->updated_by = $userId;
            $vehicle->save();

            return $vehicle;
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
     */
    private function isSameFinalPaymentRequest(MoneyEntry $entry, int $vehicleId, array $data): bool
    {
        if ((int) $entry->vehicle_id !== $vehicleId) {
            return false;
        }

        if ($entry->direction !== 'income' || $entry->category !== '尾款收入') {
            return false;
        }

        if ((int) $entry->amount !== (int) $data['amount']) {
            return false;
        }

        if ((int) $entry->cash_account_id !== (int) $data['cash_account_id']) {
            return false;
        }

        if ($entry->description !== ($data['description'] ?? null)) {
            return false;
        }

        if (! empty($data['entry_date']) && $entry->entry_date?->toDateString() !== $data['entry_date']) {
            return false;
        }

        return true;
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
