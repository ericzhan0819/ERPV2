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

    public function test_updating_a_linked_vehicle_without_touching_seller_customer_id_does_not_desync_snapshot(): void
    {
        // A request that never mentions seller_customer_id (as opposed to explicitly
        // clearing it to null) must not let free-text seller_name/phone silently
        // drift away from the customer the vehicle is still linked to.
        $user = User::factory()->create(['is_active' => true]);
        $customer = Customer::factory()->create(['name' => '已連結客戶', 'phone' => '0933333333']);
        $vehicle = Vehicle::factory()->create([
            'seller_customer_id' => $customer->id,
            'seller_name' => '已連結客戶',
            'seller_phone' => '0933333333',
        ]);

        $response = $this->actingAs($user, 'web')->putJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            // seller_customer_id intentionally omitted entirely.
            'seller_name' => '不同的名字',
            'seller_phone' => '0900000001',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.seller_customer_id', $customer->id);
        $response->assertJsonPath('data.seller_name', '已連結客戶');
        $response->assertJsonPath('data.seller_phone', '0933333333');

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'seller_customer_id' => $customer->id,
            'seller_name' => '已連結客戶',
            'seller_phone' => '0933333333',
        ]);
    }

    public function test_unrelated_vehicle_update_does_not_pull_in_the_customers_current_data(): void
    {
        // The seller snapshot is captured at link time and must stay frozen even
        // after the linked customer is later renamed — an update to a completely
        // unrelated vehicle field (here: mileage) must not become a side channel
        // for the customer's current data to retroactively overwrite history.
        $user = User::factory()->create(['is_active' => true]);
        $customer = Customer::factory()->create(['name' => '原始姓名', 'phone' => '0911111111']);
        $vehicle = Vehicle::factory()->create([
            'seller_customer_id' => $customer->id,
            'seller_name' => '原始姓名',
            'seller_phone' => '0911111111',
            'mileage_km' => 10000,
        ]);

        $customer->update(['name' => '改名後的客戶', 'phone' => '0922222222']);

        $response = $this->actingAs($user, 'web')->putJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'mileage_km' => 12000,
            // seller_customer_id and seller_name/seller_phone are all omitted —
            // this update has nothing to do with the seller.
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.mileage_km', 12000);
        $response->assertJsonPath('data.seller_customer_id', $customer->id);
        $response->assertJsonPath('data.seller_name', '原始姓名');
        $response->assertJsonPath('data.seller_phone', '0911111111');

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'mileage_km' => 12000,
            'seller_name' => '原始姓名',
            'seller_phone' => '0911111111',
        ]);
    }

    public function test_updating_a_linked_vehicle_can_explicitly_unlink_the_customer(): void
    {
        // Explicitly sending seller_customer_id = null is a deliberate unlink, unlike
        // omitting the field — the caller's free-text seller_name/phone must apply.
        $user = User::factory()->create(['is_active' => true]);
        $customer = Customer::factory()->create(['name' => '原客戶', 'phone' => '0944444444']);
        $vehicle = Vehicle::factory()->create([
            'seller_customer_id' => $customer->id,
            'seller_name' => '原客戶',
            'seller_phone' => '0944444444',
        ]);

        $response = $this->actingAs($user, 'web')->putJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'seller_customer_id' => null,
            'seller_name' => '自由輸入賣家',
            'seller_phone' => '0900000002',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.seller_customer_id', null);
        $response->assertJsonPath('data.seller_name', '自由輸入賣家');
        $response->assertJsonPath('data.seller_phone', '0900000002');
    }

    public function test_creating_a_vehicle_accepts_intake_fields(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'displacement' => '2000c.c.',
            'transmission' => '手自排',
            'fuel_type' => '汽油',
            'parking_location' => 'A區3號',
            'has_registration_document' => true,
            'has_spare_key' => true,
            'is_transfer_completed' => false,
            'is_inspection_completed' => true,
            'is_preparation_completed' => false,
            'lien_note' => '尚有貸款未結清',
            'condition_note' => '外觀良好，右前保桿有小刮痕',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.displacement', '2000c.c.');
        $response->assertJsonPath('data.transmission', '手自排');
        $response->assertJsonPath('data.fuel_type', '汽油');
        $response->assertJsonPath('data.parking_location', 'A區3號');
        $response->assertJsonPath('data.has_registration_document', true);
        $response->assertJsonPath('data.has_spare_key', true);
        $response->assertJsonPath('data.is_transfer_completed', false);
        $response->assertJsonPath('data.is_inspection_completed', true);
        $response->assertJsonPath('data.is_preparation_completed', false);
        $response->assertJsonPath('data.lien_note', '尚有貸款未結清');
        $response->assertJsonPath('data.condition_note', '外觀良好，右前保桿有小刮痕');

        $this->assertDatabaseHas('vehicles', [
            'brand' => 'Toyota',
            'displacement' => '2000c.c.',
            'has_registration_document' => true,
            'is_transfer_completed' => false,
        ]);
    }

    public function test_creating_a_vehicle_without_intake_fields_defaults_checks_to_false(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.has_registration_document', false);
        $response->assertJsonPath('data.has_spare_key', false);
        $response->assertJsonPath('data.is_transfer_completed', false);
        $response->assertJsonPath('data.is_inspection_completed', false);
        $response->assertJsonPath('data.is_preparation_completed', false);
        $response->assertJsonPath('data.displacement', null);
        $response->assertJsonPath('data.lien_note', null);
    }

    public function test_creating_a_vehicle_with_explicit_null_intake_checks_does_not_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->postJson('/api/vehicles', [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'has_registration_document' => null,
            'has_spare_key' => null,
            'is_transfer_completed' => null,
            'is_inspection_completed' => null,
            'is_preparation_completed' => null,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.has_registration_document', false);
        $response->assertJsonPath('data.has_spare_key', false);
        $response->assertJsonPath('data.is_transfer_completed', false);
        $response->assertJsonPath('data.is_inspection_completed', false);
        $response->assertJsonPath('data.is_preparation_completed', false);
    }

    public function test_updating_a_vehicle_with_explicit_null_intake_check_does_not_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['is_preparation_completed' => true]);

        $response = $this->actingAs($user, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'is_preparation_completed' => null,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_preparation_completed', false);
    }

    public function test_updating_a_vehicle_can_change_intake_check_fields(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();

        $response = $this->actingAs($user, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'is_preparation_completed' => true,
            'condition_note' => '已完成整備',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_preparation_completed', true);
        $response->assertJsonPath('data.condition_note', '已完成整備');

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'is_preparation_completed' => true,
            'condition_note' => '已完成整備',
        ]);
    }

    public function test_updating_a_listed_vehicle_rejects_reverting_preparation_completed_to_false(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create([
            'status' => 'listed',
            'is_preparation_completed' => true,
            'model' => 'Camry',
        ]);

        $response = $this->actingAs($user, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => 'Corolla',
            'license_plate' => $vehicle->license_plate,
            'is_preparation_completed' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('is_preparation_completed');

        // 整份請求必須整個被拒絕，不能靜默丟棄該欄位後把其餘欄位（例如 model）存進去，
        // 否則呼叫端會誤以為請求整體成功，卻不知道整備完成狀態的部分被悄悄忽略。
        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'is_preparation_completed' => true,
            'model' => 'Camry',
        ]);
    }

    public function test_updating_a_reserved_or_sold_vehicle_rejects_reverting_preparation_completed_to_null(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach (['reserved', 'sold'] as $status) {
            $vehicle = Vehicle::factory()->create([
                'status' => $status,
                'is_preparation_completed' => true,
                'model' => 'Camry',
            ]);

            $response = $this->actingAs($user, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
                'brand' => $vehicle->brand,
                'model' => 'Corolla',
                'license_plate' => $vehicle->license_plate,
                'is_preparation_completed' => null,
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors('is_preparation_completed');

            $this->assertDatabaseHas('vehicles', [
                'id' => $vehicle->id,
                'is_preparation_completed' => true,
                'model' => 'Camry',
            ]);
        }
    }

    public function test_updating_a_preparing_vehicle_can_still_toggle_preparation_completed_to_false(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing', 'is_preparation_completed' => true]);

        $response = $this->actingAs($user, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'is_preparation_completed' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_preparation_completed', false);
    }

    public function test_updating_a_vehicle_rechecks_database_state_before_reverting_preparation_completed(): void
    {
        // 模擬競態：呼叫端在請求開始時載入的 $vehicle 仍是 preparing（記憶體中的
        // 舊狀態），但另一個請求（listVehicle()）已在資料庫把狀態改成 listed 且
        // is_preparation_completed 設為 true。updateVehicle() 必須以交易內重新
        // lockForUpdate 讀到的最新狀態判斷，而不是沿用呼叫端傳入的舊 $vehicle，
        // 否則會誤判成仍在 preparing 而放行 false，重新造出矛盾狀態。
        $vehicle = Vehicle::factory()->create([
            'status' => 'preparing',
            'is_preparation_completed' => false,
            'model' => 'Camry',
        ]);
        $staleVehicle = Vehicle::query()->whereKey($vehicle->id)->firstOrFail();
        $user = User::factory()->create(['is_active' => true]);

        Vehicle::query()->whereKey($vehicle->id)->update([
            'status' => 'listed',
            'is_preparation_completed' => true,
        ]);

        try {
            app(VehicleService::class)->updateVehicle($staleVehicle, [
                'brand' => $staleVehicle->brand,
                'model' => 'Corolla',
                'license_plate' => $staleVehicle->license_plate,
                'is_preparation_completed' => false,
            ], $user->id);

            $this->fail('應該因為車輛已於競態中上架而拋出 ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('is_preparation_completed', $exception->errors());
        }

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'is_preparation_completed' => true,
            'model' => 'Camry',
        ]);
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
