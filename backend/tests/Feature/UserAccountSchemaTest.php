<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserAccountSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME_UNIQUE_INDEX = 'users_username_unique';

    public function test_account_fields_and_username_unique_index_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['username', 'must_change_password']));
        $this->assertTrue(Schema::hasIndex('users', self::USERNAME_UNIQUE_INDEX));

        $columns = collect(Schema::getColumns('users'))->keyBy('name');
        $this->assertTrue($columns->get('username')['nullable']);
        $this->assertFalse($columns->get('must_change_password')['nullable']);
    }

    public function test_existing_style_user_gets_safe_defaults_without_backfill_guesses(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => '既有使用者',
            'email' => 'existing@example.com',
            'password' => 'not-used-in-this-schema-test',
        ]);

        $user = DB::table('users')->find($userId);

        $this->assertNull($user->username);
        $this->assertSame(0, (int) $user->must_change_password);
    }

    public function test_multiple_null_usernames_are_allowed(): void
    {
        User::factory()->count(2)->create(['username' => null]);

        $this->assertSame(2, User::query()->whereNull('username')->count());
    }

    public function test_duplicate_non_null_username_is_rejected_by_database(): void
    {
        User::factory()->withUsername('eric')->create();

        $this->expectException(QueryException::class);

        User::factory()->withUsername('eric')->create();
    }

    public function test_factory_defaults_and_named_states_are_explicit(): void
    {
        $defaultUser = User::factory()->create();
        $pendingUser = User::factory()->mustChangePassword()->create();
        $namedUser = User::factory()->withUsername('  Sales01 ')->create();

        $this->assertNull($defaultUser->username);
        $this->assertFalse($defaultUser->must_change_password);
        $this->assertTrue($pendingUser->must_change_password);
        $this->assertSame('sales01', $namedUser->username);
    }

    public function test_admin_seeder_keeps_password_change_complete_without_overwriting_username(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $admin->update([
            'username' => 'owner',
            'must_change_password' => true,
        ]);

        $this->seed(AdminUserSeeder::class);
        $admin->refresh();

        $this->assertSame('owner', $admin->username);
        $this->assertFalse($admin->must_change_password);
    }

    public function test_migration_can_roll_back_and_run_again(): void
    {
        $migration = require database_path(
            'migrations/2026_07_23_000000_add_username_and_password_change_state_to_users_table.php'
        );

        $migration->down();

        $this->assertFalse(Schema::hasColumn('users', 'username'));
        $this->assertFalse(Schema::hasColumn('users', 'must_change_password'));
        $this->assertFalse(Schema::hasIndex('users', self::USERNAME_UNIQUE_INDEX));

        $migration->up();

        $this->assertTrue(Schema::hasColumns('users', ['username', 'must_change_password']));
        $this->assertTrue(Schema::hasIndex('users', self::USERNAME_UNIQUE_INDEX));
    }
}
