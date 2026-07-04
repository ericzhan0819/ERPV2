<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleMoneyShortcutTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_payment_creates_expense_entry_bound_to_vehicle(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/purchase-payment", [
                'amount' => 300000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', '購車付款')
            ->assertJsonPath('data.direction', 'expense');

        $this->assertDatabaseHas('money_entries', [
            'vehicle_id' => $vehicle->id,
            'category' => '購車付款',
            'amount' => 300000,
            'source_type' => 'vehicle_shortcut',
        ]);
    }

    public function test_vehicle_expense_shortcut_requires_allowed_category(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/expense", [
                'category' => '一般收入',
                'amount' => 1000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/expense", [
                'category' => '維修支出',
                'amount' => 1000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', '維修支出');
    }

    public function test_deposit_shortcut_creates_income_entry(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/deposit", [
                'amount' => 50000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', '訂金收入')
            ->assertJsonPath('data.direction', 'income');
    }

    public function test_refund_shortcut_creates_expense_entry(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/refund", [
                'amount' => 20000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', '退款')
            ->assertJsonPath('data.direction', 'expense');
    }

    public function test_shortcut_rejects_disabled_cash_account(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => false]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/deposit", [
                'amount' => 50000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertStatus(422);
    }

    public function test_shortcut_idempotency_key_is_required(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/deposit", [
                'amount' => 50000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_purchase_payment_same_idempotency_key_and_payload_only_creates_one_entry(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'amount' => 300000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($user, 'web')->postJson("/api/vehicles/{$vehicle->id}/purchase-payment", $payload)->assertCreated();
        $this->actingAs($user, 'web')->postJson("/api/vehicles/{$vehicle->id}/purchase-payment", $payload)->assertSuccessful();

        $this->assertSame(1, MoneyEntry::query()->where('idempotency_key', $idempotencyKey)->count());
        $this->assertSame(1, MoneyEntry::query()->where('vehicle_id', $vehicle->id)->count());
    }

    public function test_deposit_shortcut_same_idempotency_key_different_amount_is_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')->postJson("/api/vehicles/{$vehicle->id}/deposit", [
            'amount' => 50000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ])->assertCreated();

        $this->actingAs($user, 'web')->postJson("/api/vehicles/{$vehicle->id}/deposit", [
            'amount' => 60000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, MoneyEntry::query()->where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_expense_shortcut_same_idempotency_key_different_category_is_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')->postJson("/api/vehicles/{$vehicle->id}/expense", [
            'category' => '維修支出',
            'amount' => 1000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ])->assertCreated();

        $this->actingAs($user, 'web')->postJson("/api/vehicles/{$vehicle->id}/expense", [
            'category' => '美容支出',
            'amount' => 1000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, MoneyEntry::query()->where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_shortcut_entries_cannot_be_updated_or_deleted_via_general_money_entry_crud(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $created = $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/purchase-payment", [
                'amount' => 300000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated();

        $entryId = $created->json('data.id');

        $this->actingAs($user, 'web')
            ->patchJson("/api/money-entries/{$entryId}", [
                'entry_date' => '2026-07-02',
                'direction' => 'expense',
                'category' => '購車付款',
                'amount' => 400000,
                'cash_account_id' => $cashAccount->id,
                'vehicle_id' => $vehicle->id,
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'web')
            ->deleteJson("/api/money-entries/{$entryId}")
            ->assertStatus(422);

        $this->assertDatabaseHas('money_entries', ['id' => $entryId, 'amount' => 300000]);
    }

    public function test_shortcut_rejects_sold_vehicle(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'sold']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/expense", [
                'category' => '維修支出',
                'amount' => 1000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('money_entries', 0);
    }

    public function test_shortcut_rejects_cancelled_vehicle(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'cancelled']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/deposit", [
                'amount' => 50000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('money_entries', 0);
    }

    public function test_vehicle_money_entries_endpoint_scopes_to_vehicle(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicleA = Vehicle::factory()->create();
        $vehicleB = Vehicle::factory()->create();
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        MoneyEntry::factory()->create(['vehicle_id' => $vehicleA->id, 'cash_account_id' => $cashAccount->id, 'direction' => 'expense', 'category' => '維修支出']);
        MoneyEntry::factory()->create(['vehicle_id' => $vehicleB->id, 'cash_account_id' => $cashAccount->id, 'direction' => 'expense', 'category' => '維修支出']);

        $response = $this->actingAs($user, 'web')->getJson("/api/vehicles/{$vehicleA->id}/money-entries");

        $response->assertSuccessful();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($vehicleA->id, $response->json('data.0.vehicle_id'));
    }
}
