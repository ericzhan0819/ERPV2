<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\CashAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_account_seed_preserves_existing_values(): void
    {
        $this->seed(CashAccountSeeder::class);
        $account = CashAccount::query()->where('name', '現金')->firstOrFail();
        $account->update(['opening_balance' => 123456, 'is_active' => false]);
        $this->seed(CashAccountSeeder::class);
        $this->assertSame(123456, $account->fresh()->opening_balance);
        $this->assertFalse($account->fresh()->is_active);
        $this->assertSame(3, CashAccount::count());
    }

    public function test_production_rejects_default_admin_seed(): void
    {
        $this->app->instance('env', 'production');
        try {
            (new AdminUserSeeder)->run();
            $this->fail('Production seeding must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('local/testing', $exception->getMessage());
            $this->assertSame(0, User::count());
        }
    }

    public function test_initial_admin_requires_password_change_and_cannot_be_reset(): void
    {
        $this->artisan('app:create-initial-admin')
            ->expectsQuestion('Name', 'Owner')
            ->expectsQuestion('Email', 'owner@example.com')
            ->expectsQuestion('Password (at least 12 characters)', 'new-secret-password')
            ->expectsQuestion('Confirm password', 'new-secret-password')
            ->assertSuccessful();
        $admin = User::sole();
        $this->assertTrue($admin->must_change_password);
        $this->assertTrue($admin->is_active);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('new-secret-password', $admin->password));
        $admin->update(['email' => 'changed@example.com', 'is_active' => false]);
        $before = $admin->fresh()->getAttributes();
        $this->artisan('app:create-initial-admin')->assertFailed();
        $this->assertSame($before, $admin->fresh()->getAttributes());
        $this->assertSame(1, User::count());
    }

    public function test_invalid_initial_password_creates_nothing(): void
    {
        $this->artisan('app:create-initial-admin')
            ->expectsQuestion('Name', 'Owner')
            ->expectsQuestion('Email', 'owner@example.com')
            ->expectsQuestion('Password (at least 12 characters)', 'short')
            ->expectsQuestion('Confirm password', 'different')
            ->assertFailed();
        $this->assertSame(0, User::count());
    }
}
