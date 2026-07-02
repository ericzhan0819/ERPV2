<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

class DashboardService
{
    private const VEHICLE_STATUSES = ['preparing', 'listed', 'reserved', 'sold', 'cancelled'];

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $cashBalance = $this->accountBalance('cash');
        $bankBalance = $this->accountBalance('bank');
        $otherBalance = $this->accountBalance('other');

        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $monthlyIncome = (int) MoneyEntry::query()
            ->where('direction', 'income')
            ->whereBetween('entry_date', [$monthStart, $monthEnd])
            ->sum('amount');

        $monthlyExpense = (int) MoneyEntry::query()
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
}
