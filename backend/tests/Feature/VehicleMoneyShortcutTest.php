<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', '購車付款')
            ->assertJsonPath('data.direction', 'expense');

        $this->assertDatabaseHas('money_entries', [
            'vehicle_id' => $vehicle->id,
            'category' => '購車付款',
            'amount' => 300000,
        ]);
    }

    public function test_vehicle_expense_shortcut_requires_allowed_category(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/expense", [
                'category' => '一般收入',
                'amount' => 1000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/expense", [
                'category' => '維修支出',
                'amount' => 1000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', '維修支出');
    }

    public function test_deposit_shortcut_creates_income_entry(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/deposit", [
                'amount' => 50000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', '訂金收入')
            ->assertJsonPath('data.direction', 'income');
    }

    public function test_refund_shortcut_creates_expense_entry(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/refund", [
                'amount' => 20000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.category', '退款')
            ->assertJsonPath('data.direction', 'expense');
    }

    public function test_shortcut_rejects_disabled_cash_account(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $cashAccount = CashAccount::factory()->create(['is_active' => false]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/deposit", [
                'amount' => 50000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertStatus(422);
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
