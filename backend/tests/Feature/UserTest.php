<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_list_users(): void
    {
        $user = User::factory()->manager()->create(['is_active' => true]);

        $this->actingAs($user, 'web')->getJson('/api/users')->assertStatus(403);
    }

    public function test_non_admin_cannot_create_user(): void
    {
        $user = User::factory()->manager()->create(['is_active' => true]);

        $this->actingAs($user, 'web')->postJson('/api/users', [
            'name' => '新使用者',
            'email' => 'new-user@example.com',
            'password' => 'password123',
            'role' => 'manager',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'new-user@example.com']);
    }

    public function test_admin_can_create_update_reset_password_change_status_and_role(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);

        $createResponse = $this->actingAs($admin, 'web')->postJson('/api/users', [
            'name' => '新使用者',
            'email' => 'new-user@example.com',
            'password' => 'password123',
            'role' => 'sales',
            'phone' => '0912345678',
            'job_title' => '業務專員',
            'hire_date' => '2026-01-15',
            'notes' => '備註',
        ])->assertCreated();

        $userId = $createResponse->json('data.id');
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'new-user@example.com',
            'role' => 'sales',
            'is_admin' => false,
            'phone' => '0912345678',
            'job_title' => '業務專員',
        ]);

        $this->actingAs($admin, 'web')->putJson("/api/users/{$userId}", [
            'name' => '更新後名稱',
            'email' => 'new-user@example.com',
            'phone' => '0987654321',
        ])->assertOk()->assertJsonPath('data.name', '更新後名稱')->assertJsonPath('data.phone', '0987654321');

        $this->actingAs($admin, 'web')->patchJson("/api/users/{$userId}/role", ['role' => 'admin'])
            ->assertOk()->assertJsonPath('data.role', 'admin')->assertJsonPath('data.is_admin', true);

        $this->actingAs($admin, 'web')->patchJson("/api/users/{$userId}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAs($admin, 'web')->postJson("/api/users/{$userId}/reset-password", ['password' => 'newpassword456'])
            ->assertOk();

        $updatedUser = User::find($userId);
        $this->assertTrue(Hash::check('newpassword456', $updatedUser->password));
    }

    public function test_role_created_user_has_synced_is_admin(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);

        $this->actingAs($admin, 'web')->postJson('/api/users', [
            'name' => '經理',
            'email' => 'manager-user@example.com',
            'password' => 'password123',
            'role' => 'manager',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'manager-user@example.com', 'role' => 'manager', 'is_admin' => false]);
    }

    public function test_create_user_rejects_invalid_role(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);

        $this->actingAs($admin, 'web')->postJson('/api/users', [
            'name' => '新使用者',
            'email' => 'invalid-role@example.com',
            'password' => 'password123',
            'role' => 'owner',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_admin_cannot_demote_own_account(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);

        $this->actingAs($admin, 'web')->patchJson("/api/users/{$admin->id}/role", ['role' => 'manager'])
            ->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin', 'is_admin' => true]);
    }

    public function test_admin_cannot_disable_own_account(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);

        $this->actingAs($admin, 'web')->patchJson("/api/users/{$admin->id}/status", ['is_active' => false])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_active' => true]);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);

        $this->actingAs($admin, 'web')->deleteJson("/api/users/{$admin->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_can_delete_user_without_related_records(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $other = User::factory()->manager()->create(['is_active' => true]);

        $this->actingAs($admin, 'web')->deleteJson("/api/users/{$other->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    public function test_admin_cannot_delete_user_with_related_vehicle_records(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $other = User::factory()->manager()->create(['is_active' => true]);
        Vehicle::factory()->create(['created_by' => $other->id]);

        $this->actingAs($admin, 'web')->deleteJson("/api/users/{$other->id}")
            ->assertStatus(422)->assertJsonValidationErrors('user');

        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }

    // The HTTP layer can never manufacture "actingUser demotes someone else
    // and it's the last admin" sequentially, since a non-self actingUser who
    // passes the `admin` middleware is themselves an active admin and is
    // always counted as the one remaining. This invariant only matters when
    // two concurrent requests race each other (e.g. two admins demoting one
    // another at the same time), which SQLite's in-memory test connection
    // cannot simulate. So it's exercised directly at the service layer
    // instead, as defense-in-depth independent of the caller's identity.
    public function test_service_prevents_demoting_the_last_active_admin(): void
    {
        $onlyAdmin = User::factory()->admin()->create(['is_active' => true]);
        $actor = User::factory()->manager()->create(['is_active' => true]);

        $this->expectException(ValidationException::class);

        app(UserService::class)->setRole($actor, $onlyAdmin, 'manager');
    }

    public function test_service_prevents_disabling_the_last_active_admin(): void
    {
        $onlyAdmin = User::factory()->admin()->create(['is_active' => true]);
        $actor = User::factory()->manager()->create(['is_active' => true]);

        $this->expectException(ValidationException::class);

        app(UserService::class)->setActive($actor, $onlyAdmin, false);
    }

    public function test_service_prevents_deleting_the_last_active_admin(): void
    {
        $onlyAdmin = User::factory()->admin()->create(['is_active' => true]);
        $actor = User::factory()->manager()->create(['is_active' => true]);

        $this->expectException(ValidationException::class);

        app(UserService::class)->deleteUser($actor, $onlyAdmin);
    }

    public function test_service_allows_demoting_an_admin_when_another_active_admin_remains(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $other = User::factory()->admin()->create(['is_active' => true]);

        app(UserService::class)->setRole($admin, $other, 'manager');

        $other->refresh();
        $this->assertSame('manager', $other->role);
        $this->assertFalse($other->is_admin);
    }

    public function test_setrole_is_idempotent_for_same_target_role(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $manager = User::factory()->manager()->create(['is_active' => true]);

        $result = app(UserService::class)->setRole($admin, $manager, 'manager');

        $this->assertSame('manager', $result->role);
        $this->assertFalse($result->is_admin);
    }

    // Regression test for a Codex adversarial-review finding: a row can have
    // role='manager' with a stale is_admin=true (e.g. written by older code,
    // or otherwise desynced), which would still pass EnsureUserIsAdmin. Since
    // the requested role here already equals the current role, a naive
    // "only write when role changes" guard would treat this as a no-op and
    // leave the stale admin grant in place. setRole() must reconcile is_admin
    // whenever it doesn't match the target role, even without a role change.
    public function test_setrole_reconciles_stale_is_admin_even_without_role_change(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $desynced = User::factory()->manager()->create(['is_active' => true, 'is_admin' => true]);

        $result = app(UserService::class)->setRole($admin, $desynced, 'manager');

        $this->assertSame('manager', $result->role);
        $this->assertFalse($result->is_admin);
        $this->assertDatabaseHas('users', ['id' => $desynced->id, 'role' => 'manager', 'is_admin' => false]);
    }

    #[DataProvider('presentIsActiveValueProvider')]
    public function test_generic_update_rejects_any_present_is_active_value(mixed $isActiveValue): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $other = User::factory()->manager()->create(['is_active' => true, 'name' => '原始名稱']);

        $this->actingAs($admin, 'web')->putJson("/api/users/{$other->id}", [
            'name' => '被忽略的名稱',
            'email' => $other->email,
            'is_active' => $isActiveValue,
        ])->assertStatus(422)->assertJsonValidationErrors('is_active');

        $this->assertDatabaseHas('users', ['id' => $other->id, 'name' => '原始名稱', 'is_active' => true]);
    }

    public static function presentIsActiveValueProvider(): array
    {
        return [
            'boolean false' => [false],
            'boolean true' => [true],
            'null' => [null],
            'empty string' => [''],
            'empty array' => [[]],
        ];
    }

    #[DataProvider('presentIsAdminValueProvider')]
    public function test_generic_update_rejects_any_present_is_admin_value(mixed $isAdminValue): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $other = User::factory()->manager()->create(['is_active' => true, 'name' => '原始名稱']);

        $this->actingAs($admin, 'web')->putJson("/api/users/{$other->id}", [
            'name' => '被忽略的名稱',
            'email' => $other->email,
            'is_admin' => $isAdminValue,
        ])->assertStatus(422)->assertJsonValidationErrors('is_admin');

        $this->assertDatabaseHas('users', ['id' => $other->id, 'name' => '原始名稱', 'is_admin' => false]);
    }

    public static function presentIsAdminValueProvider(): array
    {
        return [
            'boolean false' => [false],
            'boolean true' => [true],
            'null' => [null],
            'empty string' => [''],
            'empty array' => [[]],
        ];
    }

    #[DataProvider('presentRoleValueProvider')]
    public function test_generic_update_rejects_any_present_role_value(mixed $roleValue): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $other = User::factory()->manager()->create(['is_active' => true, 'name' => '原始名稱']);

        $this->actingAs($admin, 'web')->putJson("/api/users/{$other->id}", [
            'name' => '被忽略的名稱',
            'email' => $other->email,
            'role' => $roleValue,
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertDatabaseHas('users', ['id' => $other->id, 'name' => '原始名稱', 'role' => 'manager']);
    }

    public static function presentRoleValueProvider(): array
    {
        return [
            'string' => ['admin'],
            'null' => [null],
            'empty string' => [''],
            'empty array' => [[]],
        ];
    }
}
