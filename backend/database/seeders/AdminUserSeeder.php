<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => '系統管理員',
                'password' => Hash::make('password'),
                'is_admin' => true,
                'is_active' => true,
            ]
        );
    }
}
