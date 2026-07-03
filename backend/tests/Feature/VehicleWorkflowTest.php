<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
                'idempotency_key' => (string) Str::uuid(),
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
            'idempotency_key' => (string) Str::uuid(),
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

    public function test_final_payment_is_idempotent_for_same_key(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'amount' => 380000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
            ->assertSuccessful()
            ->assertJsonPath('warning', null);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
            ->assertSuccessful()
            ->assertJsonPath('warning', null);

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
        $this->assertSame(2, MoneyEntry::query()->where('vehicle_id', $vehicle->id)->count());
    }

    public function test_final_payment_allows_distinct_idempotency_keys_to_create_distinct_entries(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 860000, 100000);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSuccessful();

        $this->assertSame(2, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_replay_succeeds_after_sale_is_closed(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'amount' => 380000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/close-sale", [])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'sold');

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
            ->assertSuccessful();

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_replay_succeeds_after_cash_account_is_deactivated(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'amount' => 380000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
            ->assertSuccessful();

        $cashAccount->update(['is_active' => false]);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
            ->assertSuccessful();

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_rejects_same_idempotency_key_for_another_vehicle(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicleA = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $vehicleB = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicleA->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicleB->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(0, MoneyEntry::query()
            ->where('vehicle_id', $vehicleB->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_rejects_same_idempotency_key_when_payload_changes(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 390000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_reserve_vehicle_rechecks_database_state_before_writing(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $staleVehicle = Vehicle::query()->whereKey($vehicle->id)->firstOrFail();

        Vehicle::query()->whereKey($vehicle->id)->update(['status' => 'sold']);

        try {
            app(VehicleService::class)->reserveVehicle($staleVehicle, [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
            ], $user->id);

            $this->fail('應該因為車輛狀態已變更而拋出 ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseCount('money_entries', 0);
    }

    public function test_second_reservation_on_same_vehicle_returns_422_and_keeps_single_deposit(): void
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
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 480000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertStatus(422);

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '訂金收入')
            ->count());
    }

    /**
     * @return Vehicle
     */
    private function createReservedVehicleWithDeposit(User $user, CashAccount $cashAccount, int $soldPrice, int $depositAmount): Vehicle
    {
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/list", [
                'asking_price' => $soldPrice,
                'floor_price' => $soldPrice - 50000,
                'listing_date' => '2026-01-01',
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => $soldPrice,
                'deposit_amount' => $depositAmount,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertSuccessful();

        return $vehicle->refresh();
    }
}
