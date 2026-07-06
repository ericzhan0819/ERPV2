<?php

namespace App\Services;

use App\Models\MoneyEntry;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private const VEHICLE_STATUSES = ['preparing', 'listed', 'reserved', 'sold', 'cancelled'];

    public function __construct(private readonly MoneyEntryService $moneyEntryService) {}

    /**
     * Dashboard production consistency target is MySQL/MariaDB, where this
     * explicitly sets REPEATABLE READ so every aggregate below reads against
     * the same snapshot instead of racing with concurrent writes between the
     * individual queries. SQLite is the automated-test driver only: it has
     * no session-level isolation level to set (and none of its statements
     * matter for cross-request concurrency in tests), so it just runs the
     * queries inside a plain transaction.
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
        $cashBalance = $this->accountBalance('cash');
        $bankBalance = $this->accountBalance('bank');
        $otherBalance = $this->accountBalance('other');

        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $monthlyIncome = (int) MoneyEntry::query()
            ->approved()
            ->where('direction', 'income')
            ->whereBetween('entry_date', [$monthStart, $monthEnd])
            ->sum('amount');

        $monthlyExpense = (int) MoneyEntry::query()
            ->approved()
            ->where('direction', 'expense')
            ->whereBetween('entry_date', [$monthStart, $monthEnd])
            ->sum('amount');

        $vehicleCounts = Vehicle::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $monthlySoldCount = Vehicle::query()
            ->where('status', 'sold')
            ->whereBetween('sold_at', [$monthStart, $monthEnd])
            ->count();

        return [
            'cash_balance' => $cashBalance,
            'bank_balance' => $bankBalance,
            'other_balance' => $otherBalance,
            'total_funds' => $cashBalance + $bankBalance + $otherBalance,
            'monthly_income' => $monthlyIncome,
            'monthly_expense' => $monthlyExpense,
            'monthly_net_flow' => $monthlyIncome - $monthlyExpense,
            'vehicle_counts' => collect(self::VEHICLE_STATUSES)
                ->mapWithKeys(fn (string $status) => [$status => (int) ($vehicleCounts[$status] ?? 0)])
                ->all(),
            'monthly_sold_count' => $monthlySoldCount,
        ];
    }

    private function accountBalance(string $type): int
    {
        return $this->moneyEntryService->balanceForType($type);
    }
}
