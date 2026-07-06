<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_cannot_see_vehicle_financial_fields_in_json(): void
    {
        $sales = User::factory()->sales()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create([
            'purchase_price' => 500000,
            'asking_price' => 600000,
            'floor_price' => 550000,
            'sold_price' => 580000,
        ]);

        $response = $this->actingAs($sales, 'web')->getJson("/api/vehicles/{$vehicle->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('vehicle.purchase_price');
        $response->assertJsonMissingPath('vehicle.asking_price');
        $response->assertJsonMissingPath('vehicle.floor_price');
        $response->assertJsonMissingPath('vehicle.sold_price');
        $response->assertJsonMissingPath('summary');
    }

    public function test_manager_can_see_vehicle_financial_fields_in_json(): void
    {
        $manager = User::factory()->manager()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['purchase_price' => 500000]);

        $response = $this->actingAs($manager, 'web')->getJson("/api/vehicles/{$vehicle->id}");

        $response->assertOk();
        $response->assertJsonPath('vehicle.purchase_price', 500000);
        $response->assertJsonPath('summary.gross_profit', 0 - 0);
    }

    public function test_sales_cannot_create_or_update_or_list_vehicle(): void
    {
        $sales = User::factory()->sales()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        $this->actingAs($sales, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'SLS-0001',
        ])->assertStatus(403);

        $this->actingAs($sales, 'web')->putJson("/api/vehicles/{$vehicle->id}", [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => $vehicle->license_plate,
        ])->assertStatus(403);

        $this->actingAs($sales, 'web')->postJson("/api/vehicles/{$vehicle->id}/list", [
            'asking_price' => 600000,
        ])->assertStatus(403);

        $this->actingAs($sales, 'web')->postJson("/api/vehicles/{$vehicle->id}/purchase-payment", [
            'amount' => 1000,
            'cash_account_id' => CashAccount::factory()->create()->id,
        ])->assertStatus(403);
    }

    public function test_sales_can_run_sales_flow_actions_but_amount_is_masked(): void
    {
        $sales = User::factory()->sales()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create();
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);

        $response = $this->actingAs($sales, 'web')->postJson("/api/vehicles/{$vehicle->id}/deposit", [
            'amount' => 20000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $response->assertJsonMissingPath('data.amount');
        $response->assertJsonMissingPath('data.cash_account_id');
        $response->assertJsonMissingPath('data.cash_account');

        $this->assertDatabaseHas('money_entries', [
            'vehicle_id' => $vehicle->id,
            'amount' => 20000,
        ]);
    }

    public function test_sales_cannot_print_vehicle_documents(): void
    {
        $sales = User::factory()->sales()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();

        $this->actingAs($sales, 'web')->getJson("/api/vehicles/{$vehicle->id}/print/intake")->assertStatus(403);
        $this->actingAs($sales, 'web')->getJson("/api/vehicles/{$vehicle->id}/print/closing")->assertStatus(403);
    }

    public function test_sales_can_submit_money_entries_as_pending_but_cannot_access_cash_account_balances(): void
    {
        $sales = User::factory()->sales()->create(['is_active' => true]);

        $this->actingAs($sales, 'web')->getJson('/api/money-entries')->assertOk();

        $response = $this->actingAs($sales, 'web')->postJson('/api/money-entries', [
            'entry_date' => now()->toDateString(),
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 1000,
            'cash_account_id' => CashAccount::factory()->create(['is_active' => true])->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $response->assertCreated();
        $this->assertDatabaseHas('money_entries', [
            'id' => $response->json('data.id'),
            'approval_status' => 'pending',
        ]);

        $this->actingAs($sales, 'web')->getJson('/api/cash-accounts')->assertStatus(403);
        $this->actingAs($sales, 'web')->getJson('/api/cash-accounts/balances')->assertStatus(403);
    }

    public function test_manager_can_access_money_entries_and_cash_account_balances_but_not_users(): void
    {
        $manager = User::factory()->manager()->create(['is_active' => true]);

        $this->actingAs($manager, 'web')->getJson('/api/money-entries')->assertOk();
        $this->actingAs($manager, 'web')->getJson('/api/cash-accounts/balances')->assertOk();
        $this->actingAs($manager, 'web')->getJson('/api/users')->assertStatus(403);
    }

    public function test_sales_gets_masked_dashboard_summary(): void
    {
        $sales = User::factory()->sales()->create(['is_active' => true]);
        MoneyEntry::factory()->create([
            'direction' => 'income',
            'amount' => 5000,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response = $this->actingAs($sales, 'web')->getJson('/api/dashboard/summary');

        $response->assertOk();
        $response->assertJsonMissingPath('cash_balance');
        $response->assertJsonMissingPath('monthly_income');
        $response->assertJsonStructure(['vehicle_counts', 'monthly_sold_count']);
    }

    public function test_admin_and_manager_get_full_dashboard_summary(): void
    {
        foreach (['admin', 'manager'] as $role) {
            $user = User::factory()->{$role}()->create(['is_active' => true]);

            $response = $this->actingAs($user, 'web')->getJson('/api/dashboard/summary');

            $response->assertOk();
            $response->assertJsonStructure(['cash_balance', 'monthly_income', 'vehicle_counts', 'monthly_sold_count']);
        }
    }

    /**
     * `role` is a plain string column (no DB-level enum constraint). An unrecognized
     * value can reach the database via a legacy row, a future role not yet handled by
     * the frontend, or manual data repair. Financial visibility must fail closed for
     * such a row instead of leaking purchase price / gross profit / dashboard amounts.
     */
    public function test_unknown_role_cannot_see_financial_fields_or_dashboard_amounts(): void
    {
        $unknownRoleUser = User::factory()->create(['is_active' => true, 'role' => 'future_role']);
        $vehicle = Vehicle::factory()->create(['purchase_price' => 500000]);

        $vehicleResponse = $this->actingAs($unknownRoleUser, 'web')->getJson("/api/vehicles/{$vehicle->id}");
        $vehicleResponse->assertOk();
        $vehicleResponse->assertJsonMissingPath('vehicle.purchase_price');
        $vehicleResponse->assertJsonMissingPath('summary');

        $dashboardResponse = $this->actingAs($unknownRoleUser, 'web')->getJson('/api/dashboard/summary');
        $dashboardResponse->assertOk();
        $dashboardResponse->assertJsonMissingPath('cash_balance');
        $dashboardResponse->assertJsonMissingPath('monthly_income');
    }

    public function test_sales_can_fetch_cash_account_options_without_balances(): void
    {
        $sales = User::factory()->sales()->create(['is_active' => true]);
        CashAccount::factory()->create(['name' => '現金', 'opening_balance' => 99999]);

        $response = $this->actingAs($sales, 'web')->getJson('/api/cash-accounts/options');

        $response->assertOk();
        $response->assertJsonMissingPath('data.0.opening_balance');
        $response->assertJsonStructure(['data' => [['id', 'name', 'type', 'is_active']]]);
    }
}
