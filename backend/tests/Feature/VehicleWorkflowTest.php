<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_workflow_from_preparing_to_sold(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/list", [
                'asking_price' => 500000,
                'floor_price' => 450000,
                'listing_date' => '2026-01-01',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'listed');

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'reserved')
            ->assertJsonPath('data.buyer_name', '王小明');

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertSuccessful()
            ->assertJsonPath('warning', null);

        $this->assertDatabaseHas('money_entries', [
            'vehicle_id' => $vehicle->id,
            'category' => '訂金收入',
            'amount' => 100000,
        ]);
        $this->assertDatabaseHas('money_entries', [
            'vehicle_id' => $vehicle->id,
            'category' => '尾款收入',
            'amount' => 380000,
        ]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'sold');

        $vehicle->refresh();
        $this->assertNotNull($vehicle->sold_at);
    }

    public function test_final_payment_mismatch_returns_warning_but_still_succeeds(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'reserved', 'sold_price' => 480000, 'buyer_name' => '王小明']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
            'amount' => 100000,
            'cash_account_id' => $cashAccount->id,
        ]);

        $response->assertSuccessful();
        $this->assertNotNull($response->json('warning'));
    }

    public function test_cannot_reserve_a_vehicle_that_is_not_listed(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertStatus(422);
    }

    public function test_cannot_reserve_with_disabled_cash_account(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $cashAccount = CashAccount::factory()->create(['is_active' => false]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertStatus(422);
    }

    public function test_cannot_close_sale_without_any_income_entry(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'reserved', 'sold_price' => 480000, 'buyer_name' => '王小明']);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertStatus(422);
    }
}
