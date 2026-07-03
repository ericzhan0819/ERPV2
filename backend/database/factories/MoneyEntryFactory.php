<?php

namespace Database\Factories;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MoneyEntry>
 */
class MoneyEntryFactory extends Factory
{
    protected $model = MoneyEntry::class;

    public function definition(): array
    {
        return [
            'vehicle_id' => null,
            'cash_account_id' => CashAccount::factory(),
            'entry_date' => now()->toDateString(),
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => fake()->numberBetween(1000, 100000),
            'counterparty_name' => null,
            'description' => null,
            'idempotency_key' => null,
        ];
    }
}
