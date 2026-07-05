<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_list_users(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_admin' => false]);

        $this->actingAs($user, 'web')->getJson('/api/users')->assertStatus(403);
    }

    public function test_non_admin_cannot_create_user(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_admin' => false]);

        $this->actingAs($user, 'web')->postJson('/api/users', [
            'name' => '新使用者',
            'email' => 'new-user@example.com',
            'password' => 'password123',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'new-user@example.com']);
    }

    public function test_admin_can_create_update_reset_password_and_change_status(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);

        $createResponse = $this->actingAs($admin, 'web')->postJson('/api/users', [
            'name' => '新使用者',
            'email' => 'new-user@example.com',
            'password' => 'password123',
            'is_admin' => false,
        ])->assertCreated();

        $userId = $createResponse->json('data.id');
        $this->assertDatabaseHas('users', ['id' => $userId, 'email' => 'new-user@example.com', 'is_admin' => false]);

        $this->actingAs($admin, 'web')->putJson("/api/users/{$userId}", [
            'name' => '更新後名稱',
            'email' => 'new-user@example.com',
            'is_admin' => true,
        ])->assertOk()->assertJsonPath('data.name', '更新後名稱')->assertJsonPath('data.is_admin', true);

        $this->actingAs($admin, 'web')->patchJson("/api/users/{$userId}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAs($admin, 'web')->postJson("/api/users/{$userId}/reset-password", ['password' => 'newpassword456'])
            ->assertOk();

        $updatedUser = User::find($userId);
        $this->assertTrue(Hash::check('newpassword456', $updatedUser->password));
    }

    public function test_admin_cannot_demote_own_account(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);

        $this->actingAs($admin, 'web')->putJson("/api/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'is_admin' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('is_admin');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_admin' => true]);
    }

    public function test_admin_cannot_disable_own_account(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);

        $this->actingAs($admin, 'web')->patchJson("/api/users/{$admin->id}/status", ['is_active' => false])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_active' => true]);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);

        $this->actingAs($admin, 'web')->deleteJson("/api/users/{$admin->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_can_delete_user_without_related_records(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);
        $other = User::factory()->create(['is_active' => true, 'is_admin' => false]);

        $this->actingAs($admin, 'web')->deleteJson("/api/users/{$other->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    #[DataProvider('presentIsActiveValueProvider')]
    public function test_generic_update_rejects_any_present_is_active_value(mixed $isActiveValue): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);
        $other = User::factory()->create(['is_active' => true, 'is_admin' => false, 'name' => '原始名稱']);

        $this->actingAs($admin, 'web')->putJson("/api/users/{$other->id}", [
            'name' => '被忽略的名稱',
            'email' => $other->email,
            'is_admin' => false,
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
}
