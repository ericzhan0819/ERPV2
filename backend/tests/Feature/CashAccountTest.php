<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CashAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_create_cash_account(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_admin' => false]);

        $this->actingAs($user, 'web')->postJson('/api/cash-accounts', [
            'name' => '測試帳戶',
            'type' => 'cash',
            'opening_balance' => 0,
            'is_active' => true,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('cash_accounts', ['name' => '測試帳戶']);
    }

    public function test_non_admin_cannot_update_cash_account(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_admin' => false]);
        $cashAccount = CashAccount::factory()->create(['name' => '原始名稱']);

        $this->actingAs($user, 'web')->putJson("/api/cash-accounts/{$cashAccount->id}", [
            'name' => '被竄改的名稱',
            'type' => $cashAccount->type,
            'opening_balance' => 999999,
            'is_active' => $cashAccount->is_active,
        ])->assertStatus(403);

        $this->assertDatabaseHas('cash_accounts', ['id' => $cashAccount->id, 'name' => '原始名稱']);
    }

    public function test_non_admin_cannot_change_cash_account_status(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_admin' => false]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')->patchJson("/api/cash-accounts/{$cashAccount->id}/status", ['is_active' => false])
            ->assertStatus(403);

        $this->assertDatabaseHas('cash_accounts', ['id' => $cashAccount->id, 'is_active' => true]);
    }

    public function test_non_admin_cannot_delete_cash_account(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_admin' => false]);
        $cashAccount = CashAccount::factory()->create();

        $this->actingAs($user, 'web')->deleteJson("/api/cash-accounts/{$cashAccount->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('cash_accounts', ['id' => $cashAccount->id]);
    }

    public function test_non_admin_can_still_read_cash_accounts_and_balances(): void
    {
        $user = User::factory()->create(['is_active' => true, 'is_admin' => false]);
        CashAccount::factory()->create();

        $this->actingAs($user, 'web')->getJson('/api/cash-accounts')->assertOk();
        $this->actingAs($user, 'web')->getJson('/api/cash-accounts/balances')->assertOk();
    }

    public function test_admin_can_create_update_change_status_and_delete_cash_account(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);

        $createResponse = $this->actingAs($admin, 'web')->postJson('/api/cash-accounts', [
            'name' => '管理員新增帳戶',
            'type' => 'other',
            'opening_balance' => 1000,
            'is_active' => true,
        ])->assertCreated();

        $accountId = $createResponse->json('data.id');

        $this->actingAs($admin, 'web')->putJson("/api/cash-accounts/{$accountId}", [
            'name' => '管理員新增帳戶2',
            'type' => 'other',
            'opening_balance' => 2000,
        ])->assertOk()->assertJsonPath('data.name', '管理員新增帳戶2');

        $this->actingAs($admin, 'web')->patchJson("/api/cash-accounts/{$accountId}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAs($admin, 'web')->deleteJson("/api/cash-accounts/{$accountId}")->assertOk();
        $this->assertDatabaseMissing('cash_accounts', ['id' => $accountId]);
    }

    #[DataProvider('presentIsActiveValueProvider')]
    public function test_generic_update_rejects_any_present_is_active_value(mixed $isActiveValue): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);
        $cashAccount = CashAccount::factory()->create(['name' => '原始名稱', 'is_active' => true]);

        // A legacy/cached client that still sends is_active on the generic
        // update endpoint - even as null, "", or [] - must get a loud
        // rejection, not a silent 200 that discards the field while implying
        // the status change took effect. `missing` (not `prohibited`) is what
        // makes null/""/[] rejected too, since `prohibited` only rejects
        // "truthy" values.
        $this->actingAs($admin, 'web')->putJson("/api/cash-accounts/{$cashAccount->id}", [
            'name' => '被忽略的名稱',
            'type' => $cashAccount->type,
            'opening_balance' => $cashAccount->opening_balance,
            'is_active' => $isActiveValue,
        ])->assertStatus(422)->assertJsonValidationErrors('is_active');

        $this->assertDatabaseHas('cash_accounts', [
            'id' => $cashAccount->id,
            'name' => '原始名稱',
            'is_active' => true,
        ]);
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

    public function test_stale_metadata_update_does_not_undo_concurrent_deactivation(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);
        $cashAccount = CashAccount::factory()->create([
            'name' => '原始名稱',
            'type' => 'bank',
            'opening_balance' => 1000,
            'is_active' => true,
        ]);

        // Simulates an admin who opened the edit form while the account was
        // still active, and only submits (name/type/opening_balance only, per
        // the current client contract) after a second admin deactivates it
        // concurrently via the dedicated status endpoint.
        $metadataOnlyUpdate = [
            'name' => '編輯後名稱',
            'type' => 'bank',
            'opening_balance' => 1000,
        ];

        $this->actingAs($admin, 'web')->patchJson("/api/cash-accounts/{$cashAccount->id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAs($admin, 'web')->putJson("/api/cash-accounts/{$cashAccount->id}", $metadataOnlyUpdate)
            ->assertOk()
            ->assertJsonPath('data.name', '編輯後名稱')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('cash_accounts', [
            'id' => $cashAccount->id,
            'name' => '編輯後名稱',
            'is_active' => false,
        ]);
    }

    public function test_status_update_does_not_change_other_fields(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);
        $cashAccount = CashAccount::factory()->create([
            'name' => '保留名稱',
            'type' => 'bank',
            'opening_balance' => 5000,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'web')->patchJson("/api/cash-accounts/{$cashAccount->id}/status", ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseHas('cash_accounts', [
            'id' => $cashAccount->id,
            'name' => '保留名稱',
            'type' => 'bank',
            'opening_balance' => 5000,
            'is_active' => false,
        ]);
    }

    public function test_status_update_is_idempotent_when_repeated(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($admin, 'web')->patchJson("/api/cash-accounts/{$cashAccount->id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        // Simulates a client retry (e.g. after a lost response) resending the
        // same target state; it must land on the same state, not flip back on.
        $this->actingAs($admin, 'web')->patchJson("/api/cash-accounts/{$cashAccount->id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('cash_accounts', ['id' => $cashAccount->id, 'is_active' => false]);
    }

    public function test_admin_cannot_delete_cash_account_with_money_entries(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_admin' => true]);
        $cashAccount = CashAccount::factory()->create();
        MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '一般收入',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($admin, 'web')->deleteJson("/api/cash-accounts/{$cashAccount->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_account_id');

        $this->assertDatabaseHas('cash_accounts', ['id' => $cashAccount->id]);
    }
}
