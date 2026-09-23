<?php

namespace Database\Factories;

use App\Models\CashAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashAccount>
 */
class CashAccountFactory extends Factory
{
    protected $model = CashAccount::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'type' => 'cash',
            'opening_balance' => 0,
            'is_active' => true,
        ];
    }
}
