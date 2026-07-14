<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** 此段說明相鄰程式碼的用途與預期行為。 */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            CashAccountSeeder::class,
            CommissionPlanSeeder::class,
        ]);
    }
}
