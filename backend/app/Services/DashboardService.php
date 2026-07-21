<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\Vehicle;
use App\Support\TaipeiMonthRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private const INVENTORY_STATUSES = ['preparing', 'listed', 'reserved'];

    public function __construct(private readonly MoneyEntryService $moneyEntryService) {}

    /**
     * 正式環境使用 MySQL/MariaDB，這裡明確設定 REPEATABLE READ，讓下方每個彙總查詢
     * 都讀取同一份資料快照，不會在查詢之間受到並行寫入影響。SQLite 僅用於自動測試，
     * 不支援連線層級的隔離設定，因此只使用一般交易執行查詢。
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return DB::transaction(fn () => $this->buildSummary());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(): array
    {
        $today = Carbon::now(config('app.timezone'))->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $soldMonth = $today->format('Y-m');
        [$monthStart, $nextMonthStart] = TaipeiMonthRange::fromYearMonth($soldMonth);
        $trendStart = $today->copy()->subDays(29);

        $cashBalance = $this->accountBalance('cash');

        $monthlyIncome = $this->approvedAmountForPeriod('income', $monthStart, $nextMonthStart);
        $monthlyExpense = $this->approvedAmountForPeriod('expense', $monthStart, $nextMonthStart);

        $vehicleCounts = Vehicle::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $preparingCounts = Vehicle::query()
            ->where('status', 'preparing')
            ->selectRaw('is_preparation_completed, count(*) as count')
            ->groupBy('is_preparation_completed')
            ->pluck('count', 'is_preparation_completed');

        $monthlySoldVehicles = $this->soldVehiclesForPeriod($monthStart, $nextMonthStart);
        $trendSoldVehicles = $this->soldVehiclesForPeriod($trendStart, $tomorrow);

        $monthlyGrossProfit = array_sum($this->grossProfitsByVehicle($monthlySoldVehicles->pluck('id')));
        $trendGrossProfits = $this->grossProfitsByVehicle($trendSoldVehicles->pluck('id'));

        $workOverview = [
            'preparation_pending_count' => (int) ($preparingCounts[0] ?? 0),
            'listing_pending_count' => (int) ($preparingCounts[1] ?? 0),
            'delivery_pending_count' => (int) ($vehicleCounts['reserved'] ?? 0),
            'pending_money_entry_count' => MoneyEntry::query()
                ->where('approval_status', MoneyEntry::APPROVAL_PENDING)
                ->count(),
        ];

        $inventoryCount = collect(self::INVENTORY_STATUSES)
            ->sum(fn (string $status): int => (int) ($vehicleCounts[$status] ?? 0));

        return [
            'work_overview' => $workOverview,
            'business_overview' => [
                'inventory_count' => $inventoryCount,
                'sold_month' => $soldMonth,
                'cash_balance' => $cashBalance,
                'monthly_income' => $monthlyIncome,
                'monthly_expense' => $monthlyExpense,
                'monthly_gross_profit' => $monthlyGrossProfit,
                'monthly_sold_count' => $monthlySoldVehicles->count(),
            ],
            'trends' => [
                'sales_count' => $this->salesCountTrend($trendStart, $trendSoldVehicles),
                'gross_profit' => $this->grossProfitTrend($trendStart, $trendSoldVehicles, $trendGrossProfits),
                'cash_balance' => $this->cashBalanceTrend($trendStart, $today),
            ],
        ];
    }

    private function approvedAmountForPeriod(string $direction, Carbon $start, Carbon $end): int
    {
        return (int) MoneyEntry::query()
            ->approved()
            ->where('direction', $direction)
            ->where('entry_date', '>=', $start->toDateString())
            ->where('entry_date', '<', $end->toDateString())
            ->sum('amount');
    }

    /**
     * @return Collection<int, Vehicle>
     */
    private function soldVehiclesForPeriod(Carbon $start, Carbon $end): Collection
    {
        return Vehicle::query()
            ->select(['id', 'sold_at'])
            ->where('status', 'sold')
            ->where('sold_at', '>=', $start)
            ->where('sold_at', '<', $end)
            ->orderBy('sold_at')
            ->get();
    }

    /**
     * @param  Collection<int, int>  $vehicleIds
     * @return array<int, int>
     */
    private function grossProfitsByVehicle(Collection $vehicleIds): array
    {
        if ($vehicleIds->isEmpty()) {
            return [];
        }

        return MoneyEntry::query()
            ->approved()
            ->whereIn('vehicle_id', $vehicleIds)
            ->selectRaw("vehicle_id,
                SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END) as income_total,
                SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END) as expense_total")
            ->groupBy('vehicle_id')
            ->get()
            ->mapWithKeys(fn (MoneyEntry $entry): array => [
                (int) $entry->vehicle_id => (int) $entry->getAttribute('income_total') - (int) $entry->getAttribute('expense_total'),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Vehicle>  $vehicles
     * @return array<int, array{date: string, count: int}>
     */
    private function salesCountTrend(Carbon $start, Collection $vehicles): array
    {
        $counts = $vehicles
            ->countBy(fn (Vehicle $vehicle): string => $vehicle->sold_at->toDateString());

        return $this->dateRange($start)
            ->map(fn (Carbon $date): array => [
                'date' => $date->toDateString(),
                'count' => (int) ($counts[$date->toDateString()] ?? 0),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Vehicle>  $vehicles
     * @param  array<int, int>  $grossProfits
     * @return array<int, array{date: string, amount: int}>
     */
    private function grossProfitTrend(Carbon $start, Collection $vehicles, array $grossProfits): array
    {
        $dailyAmounts = [];

        foreach ($vehicles as $vehicle) {
            $date = $vehicle->sold_at->toDateString();
            $dailyAmounts[$date] = ($dailyAmounts[$date] ?? 0) + ($grossProfits[$vehicle->id] ?? 0);
        }

        return $this->dateRange($start)
            ->map(fn (Carbon $date): array => [
                'date' => $date->toDateString(),
                'amount' => (int) ($dailyAmounts[$date->toDateString()] ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<int, array{date: string, balance: int}>
     */
    private function cashBalanceTrend(Carbon $start, Carbon $today): array
    {
        $openingBalance = (int) CashAccount::query()
            ->where('type', 'cash')
            ->sum('opening_balance');

        $balanceBeforeRange = $openingBalance + $this->cashNetBefore($start);
        $dailyNet = MoneyEntry::query()
            ->approved()
            ->join('cash_accounts', 'cash_accounts.id', '=', 'money_entries.cash_account_id')
            ->where('cash_accounts.type', 'cash')
            ->where('entry_date', '>=', $start->toDateString())
            ->where('entry_date', '<=', $today->toDateString())
            ->selectRaw("entry_date,
                SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END) as income_total,
                SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END) as expense_total")
            ->groupBy('entry_date')
            ->get()
            ->mapWithKeys(fn (MoneyEntry $entry): array => [
                $entry->entry_date->toDateString() => (int) $entry->getAttribute('income_total') - (int) $entry->getAttribute('expense_total'),
            ]);

        $balance = $balanceBeforeRange;

        return $this->dateRange($start)
            ->map(function (Carbon $date) use (&$balance, $dailyNet): array {
                $balance += (int) ($dailyNet[$date->toDateString()] ?? 0);

                return [
                    'date' => $date->toDateString(),
                    'balance' => $balance,
                ];
            })
            ->all();
    }

    private function cashNetBefore(Carbon $date): int
    {
        $totals = MoneyEntry::query()
            ->approved()
            ->join('cash_accounts', 'cash_accounts.id', '=', 'money_entries.cash_account_id')
            ->where('cash_accounts.type', 'cash')
            ->where('entry_date', '<', $date->toDateString())
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) as income_total,
                COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) as expense_total")
            ->first();

        return (int) $totals->getAttribute('income_total') - (int) $totals->getAttribute('expense_total');
    }

    /**
     * @return Collection<int, Carbon>
     */
    private function dateRange(Carbon $start): Collection
    {
        return collect(range(0, 29))
            ->map(fn (int $offset): Carbon => $start->copy()->addDays($offset));
    }

    private function accountBalance(string $type): int
    {
        return $this->moneyEntryService->balanceForType($type);
    }
}
