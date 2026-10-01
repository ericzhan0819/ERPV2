<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Seeders are only available in local/testing environments.');
        }

        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => '系統管理員',
                'password' => Hash::make('password'),
                'must_change_password' => true,
                'role' => User::ROLE_ADMIN,
                'is_admin' => true,
                'is_active' => true,
            ]
        );
    }
}
