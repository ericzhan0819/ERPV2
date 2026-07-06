<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VehicleTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_vehicle_generates_stock_no_and_defaults_status_to_preparing(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'preparing');
        $this->assertNotEmpty($response->json('data.stock_no'));

        $this->assertDatabaseHas('vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'status' => 'preparing',
        ]);
    }

    public function test_creating_a_vehicle_without_plate_or_vin_fails_validation(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
        ]);

        $response->assertStatus(422);
    }

    public function test_seller_customer_id_overrides_seller_name_and_phone_with_the_customers_own_data(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $customer = Customer::factory()->create(['name' => '客戶端真實姓名', 'phone' => '0900000001']);

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'seller_customer_id' => $customer->id,
            // Deliberately mismatched free-text values: the customer link must win,
            // otherwise the vehicle record could point at one customer while
            // displaying a different name/phone for the seller.
            'seller_name' => '不一致的名字',
            'seller_phone' => '0999999999',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.seller_name', '客戶端真實姓名');
        $response->assertJsonPath('data.seller_phone', '0900000001');

        $this->assertDatabaseHas('vehicles', [
            'seller_customer_id' => $customer->id,
            'seller_name' => '客戶端真實姓名',
            'seller_phone' => '0900000001',
        ]);
    }

    public function test_updating_seller_customer_id_resyncs_seller_snapshot(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['seller_name' => '舊賣家', 'seller_phone' => '0911111111']);
        $customer = Customer::factory()->create(['name' => '新賣家', 'phone' => '0922222222']);

        $response = $this->actingAs($user, 'web')->putJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'seller_customer_id' => $customer->id,
            'seller_name' => '仍然不一致',
            'seller_phone' => '0900000000',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.seller_name', '新賣家');
        $response->assertJsonPath('data.seller_phone', '0922222222');
    }

    public function test_index_supports_search_and_status_filter(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Vehicle::factory()->create(['brand' => 'Toyota', 'status' => 'preparing', 'license_plate' => 'AAA-001']);
        Vehicle::factory()->create(['brand' => 'Honda', 'status' => 'listed', 'license_plate' => 'BBB-002']);

        $response = $this->actingAs($user, 'web')->getJson('/api/vehicles?status=listed');

        $response->assertSuccessful();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Honda', $response->json('data.0.brand'));
    }

    public function test_show_returns_financial_summary_and_money_entries(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();
        $cashAccount = CashAccount::factory()->create();

        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'amount' => 50000,
        ]);
        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'amount' => 20000,
        ]);

        $response = $this->actingAs($user, 'web')->getJson("/api/vehicles/{$vehicle->id}");

        $response->assertSuccessful();
        $response->assertJsonPath('summary.income_total', 50000);
        $response->assertJsonPath('summary.expense_total', 20000);
        $response->assertJsonPath('summary.gross_profit', 30000);
        $this->assertCount(2, $response->json('money_entries'));
    }

    public function test_update_and_delete_vehicle(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();

        $this->actingAs($user, 'web')
            ->patchJson("/api/vehicles/{$vehicle->id}", [
                'brand' => $vehicle->brand,
                'model' => 'Updated Model',
                'license_plate' => $vehicle->license_plate ?? 'ZZZ-9999',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.model', 'Updated Model');

        $this->actingAs($user, 'web')
            ->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
    }

    public function test_cannot_delete_vehicle_with_money_entries(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $cashAccount = CashAccount::factory()->create();

        $entry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'amount' => 10000,
        ]);

        $this->actingAs($user, 'web')
            ->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
        $this->assertDatabaseHas('money_entries', ['id' => $entry->id]);
    }

    public function test_cannot_delete_vehicle_that_is_not_preparing(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);

        $this->actingAs($user, 'web')
            ->deleteJson("/api/vehicles/{$vehicle->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    }

    public function test_delete_vehicle_rechecks_database_state_before_deleting(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $staleVehicle = Vehicle::query()->whereKey($vehicle->id)->firstOrFail();

        Vehicle::query()->whereKey($vehicle->id)->update(['status' => 'listed']);

        try {
            app(VehicleService::class)->deleteVehicle($staleVehicle);

            $this->fail('應該因為車輛狀態已變更而拋出 ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    }

    public function test_delete_vehicle_rechecks_money_entries_before_deleting(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $staleVehicle = Vehicle::query()->whereKey($vehicle->id)->firstOrFail();
        $cashAccount = CashAccount::factory()->create();

        $entry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'amount' => 10000,
        ]);

        try {
            app(VehicleService::class)->deleteVehicle($staleVehicle);

            $this->fail('應該因為已有收支紀錄而拋出 ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
        $this->assertDatabaseHas('money_entries', ['id' => $entry->id]);
    }
}
