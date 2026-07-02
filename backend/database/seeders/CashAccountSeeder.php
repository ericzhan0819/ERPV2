<?php

namespace Database\Seeders;

use App\Models\CashAccount;
use Illuminate\Database\Seeder;

class CashAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['name' => '現金', 'type' => 'cash', 'opening_balance' => 0],
            ['name' => '主要銀行', 'type' => 'bank', 'opening_balance' => 0],
            ['name' => '其他', 'type' => 'other', 'opening_balance' => 0],
        ];

        foreach ($accounts as $account) {
            CashAccount::updateOrCreate(
                ['name' => $account['name']],
                [
                    'type' => $account['type'],
                    'opening_balance' => $account['opening_balance'],
                    'is_active' => true,
                ]
            );
        }
    }
}
