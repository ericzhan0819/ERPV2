<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehiclePrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_intake_returns_vehicle_data(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['brand' => 'Toyota', 'model' => 'Camry']);

        $response = $this->actingAs($user, 'web')->getJson("/api/vehicles/{$vehicle->id}/print/intake");

        $response->assertSuccessful();
        $response->assertJsonPath('vehicle.id', $vehicle->id);
        $response->assertJsonPath('vehicle.stock_no', $vehicle->stock_no);
        $this->assertNotEmpty($response->json('printed_at'));
    }

    public function test_print_closing_returns_summary_and_money_entries(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'sold', 'sold_price' => 500000]);
        $cashAccount = CashAccount::factory()->create();

        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'amount' => 500000,
        ]);
        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'amount' => 300000,
        ]);

        $response = $this->actingAs($user, 'web')->getJson("/api/vehicles/{$vehicle->id}/print/closing");

        $response->assertSuccessful();
        $response->assertJsonPath('vehicle.id', $vehicle->id);
        $response->assertJsonPath('summary.income_total', 500000);
        $response->assertJsonPath('summary.expense_total', 300000);
        $response->assertJsonPath('summary.gross_profit', 200000);
        $this->assertCount(2, $response->json('money_entries'));
    }

    public function test_print_closing_rejects_vehicles_that_are_not_sold(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);

        $response = $this->actingAs($user, 'web')->getJson("/api/vehicles/{$vehicle->id}/print/closing");

        $response->assertStatus(422);
    }

    public function test_print_endpoints_require_authentication(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->getJson("/api/vehicles/{$vehicle->id}/print/intake")->assertUnauthorized();
        $this->getJson("/api/vehicles/{$vehicle->id}/print/closing")->assertUnauthorized();
    }
}
