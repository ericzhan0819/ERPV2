<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        return DB::transaction(function () use ($data, $userId) {
            $this->assertCashAccountActive((int) $data['cash_account_id']);
            $this->assertCategoryRules($data['category'], $data['direction'], $data['vehicle_id'] ?? null);

            $entry = new MoneyEntry($data);
            $entry->created_by = $userId;
            $entry->updated_by = $userId;
            $entry->save();

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateEntry(MoneyEntry $entry, array $data, int $userId): MoneyEntry
    {
        return DB::transaction(function () use ($entry, $data, $userId) {
            $lockedEntry = MoneyEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

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
            MoneyEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail()->delete();
        });
    }

    /**
     * 車輛快捷收支（購車付款／單車支出／收訂金／退款）共用的建立邏輯。
     *
     * @param  array<string, mixed>  $data
     */
    public function recordVehicleShortcut(Vehicle $vehicle, string $direction, string $category, array $data, int $userId): MoneyEntry
    {
        return DB::transaction(function () use ($vehicle, $direction, $category, $data, $userId) {
            $this->assertCashAccountActive((int) $data['cash_account_id']);

            $entry = new MoneyEntry([
                'vehicle_id' => $vehicle->id,
                'cash_account_id' => $data['cash_account_id'],
                'entry_date' => $data['entry_date'] ?? now()->toDateString(),
                'direction' => $direction,
                'category' => $category,
                'amount' => $data['amount'],
                'counterparty_name' => $data['counterparty_name'] ?? null,
                'description' => $data['description'] ?? null,
            ]);
            $entry->created_by = $userId;
            $entry->updated_by = $userId;
            $entry->save();

            return $entry;
        });
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
}
