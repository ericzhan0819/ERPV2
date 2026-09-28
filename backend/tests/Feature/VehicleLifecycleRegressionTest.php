<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\Customer;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VehicleLifecycleRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_refunded_reservation_can_be_relisted_and_reserved_for_another_buyer(): void
    {
        $admin = User::factory()->admin()->create();
        $account = CashAccount::factory()->create();
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);
        $this->actingAs($admin);
        $payload = ['buyer_name' => '甲', 'sold_price' => 100, 'deposit_amount' => 100, 'cash_account_id' => $account->id, 'sales_agent_id' => $admin->id, 'idempotency_key' => (string) Str::uuid()];
        $this->postJson("/api/vehicles/{$vehicle->id}/reserve", $payload)->assertOk();
        $this->postJson("/api/vehicles/{$vehicle->id}/unreserve")->assertUnprocessable();
        $this->postJson("/api/vehicles/{$vehicle->id}/refund", ['amount' => 100, 'cash_account_id' => $account->id, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson("/api/vehicles/{$vehicle->id}/unreserve")->assertOk()->assertJsonPath('data.buyer_name', null)->assertJsonPath('data.sold_price', null);
        $this->getJson("/api/public/vehicles/{$vehicle->id}")->assertOk()->assertJsonPath('data.availability', 'available');
        $payload['buyer_name'] = '乙';
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->postJson("/api/vehicles/{$vehicle->id}/reserve", $payload)->assertOk();
        $this->postJson("/api/vehicles/{$vehicle->id}/close-sale")->assertOk();
    }

    public function test_cancel_permissions_pending_guard_and_terminal_money_lock(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'reserved']);
        $admin = User::factory()->admin()->create();
        $this->actingAs(User::factory()->sales()->create())->postJson("/api/vehicles/{$vehicle->id}/cancel")->assertForbidden();
        $pending = MoneyEntry::factory()->create(['vehicle_id' => $vehicle->id, 'approval_status' => 'pending', 'category' => '維修支出', 'direction' => 'expense']);
        $this->actingAs($admin)->postJson("/api/vehicles/{$vehicle->id}/cancel")->assertUnprocessable();
        $pending->forceFill(['approval_status' => 'rejected'])->save();
        $this->postJson("/api/vehicles/{$vehicle->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson("/api/public/vehicles/{$vehicle->id}")->assertNotFound();
        $this->postJson("/api/vehicles/{$vehicle->id}/unreserve")->assertUnprocessable();
        $this->postJson("/api/vehicles/{$vehicle->id}/refund", ['amount' => 1, 'cash_account_id' => $pending->cash_account_id, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_pending_sales_entries_block_unreserve_even_with_zero_approved_net(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'reserved']);
        MoneyEntry::factory()->create(['vehicle_id' => $vehicle->id, 'category' => '退款', 'direction' => 'expense', 'approval_status' => 'pending']);
        $this->actingAs(User::factory()->manager()->create())->postJson("/api/vehicles/{$vehicle->id}/unreserve")->assertUnprocessable();
    }

    public function test_listing_preserves_omitted_optional_fields(): void
    {
        $v = Vehicle::factory()->create(['floor_price' => 100, 'sales_note' => '保留說明']);
        $this->actingAs(User::factory()->admin()->create())->postJson("/api/vehicles/{$v->id}/list", ['asking_price' => 200])->assertOk();
        $this->assertSame(100, $v->fresh()->floor_price);
        $this->assertSame('保留說明', $v->fresh()->sales_note);
    }

    public function test_same_seller_link_preserves_snapshot_and_rebinding_merges_role(): void
    {
        $seller = Customer::factory()->create(['name' => '現名', 'phone' => '0911111111']);
        $vehicle = Vehicle::factory()->create(['seller_customer_id' => $seller->id, 'seller_name' => '舊名', 'seller_phone' => '0922222222']);
        $this->actingAs(User::factory()->admin()->create());
        foreach (['patchJson', 'putJson'] as $method) {
            $this->$method("/api/vehicles/{$vehicle->id}", ['brand' => $vehicle->brand, 'model' => $vehicle->model, 'license_plate' => $vehicle->license_plate, 'seller_customer_id' => $seller->id, 'seller_name' => '現名', 'color' => '白'])->assertOk();
            $this->assertSame('舊名', $vehicle->fresh()->seller_name);
            $this->assertSame('0922222222', $vehicle->fresh()->seller_phone);
        }
        $buyer = Customer::factory()->create(['customer_type' => 'buyer']);
        $this->patchJson("/api/vehicles/{$vehicle->id}", ['brand' => $vehicle->brand, 'model' => $vehicle->model, 'license_plate' => $vehicle->license_plate, 'seller_customer_id' => $buyer->id])->assertOk();
        $this->assertSame('both', $buyer->fresh()->customer_type);
        $this->assertSame($buyer->name, $vehicle->fresh()->seller_name);
    }

    public function test_placeholder_phones_do_not_participate_in_identity(): void
    {
        foreach (['-', '無', 'N/A', '   '] as $phone) {
            $this->assertNull(Customer::normalizeIdentityPhone($phone));
            Customer::factory()->count(2)->create(['name' => '同名', 'phone' => $phone]);
        }
        $this->assertDatabaseCount('customers', 8);
    }

    public function test_placeholder_phone_vehicle_intakes_create_distinct_customers(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach (['-', '無', 'N/A', ' '] as $phone) {
            $ids = [];
            for ($i = 0; $i < 2; $i++) {
                $response = $this->postJson('/api/vehicles', [
                    'brand' => 'Toyota', 'model' => 'Corolla', 'license_plate' => (string) Str::uuid(),
                    'purchase_agent_id' => auth()->id(), 'seller_name' => '同名', 'seller_phone' => $phone, 'idempotency_key' => (string) Str::uuid(),
                ])->assertCreated();
                $ids[] = $response->json('data.seller_customer_id');
            }
            $this->assertNotNull($ids[0]);
            $this->assertNotSame($ids[0], $ids[1]);
        }
    }

    public static function collectionStates(): array
    {
        $rows = [];
        foreach (['preparing', 'listed', 'reserved', 'sold', 'cancelled'] as $status) {
            foreach (['訂金收入', '尾款收入'] as $category) {
                $rows[] = [$status, $category];
            }
        }

        return $rows;
    }

    #[DataProvider('collectionStates')]
    public function test_manual_and_shortcut_collection_state_matrix(string $status, string $category): void
    {
        $user = User::factory()->admin()->create();
        $vehicle = Vehicle::factory()->create(['status' => $status]);
        $account = CashAccount::factory()->create();
        $this->actingAs($user);
        $payload = ['vehicle_id' => $vehicle->id, 'cash_account_id' => $account->id, 'category' => $category, 'direction' => 'income', 'amount' => 10, 'entry_date' => '2026-09-01', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/money-entries', $payload)->assertStatus($status === 'reserved' ? 201 : 422);
        $entry = MoneyEntry::factory()->create(['source_type' => 'manual', 'approval_status' => 'pending', 'vehicle_id' => null, 'category' => '一般收入', 'direction' => 'income']);
        $this->putJson("/api/money-entries/{$entry->id}", $payload)->assertStatus($status === 'reserved' ? 200 : 422);
        if ($category === '訂金收入') {
            $payload['idempotency_key'] = (string) Str::uuid();
            $this->postJson("/api/vehicles/{$vehicle->id}/deposit", $payload)->assertStatus($status === 'reserved' ? 201 : 422);
        }
    }

    public function test_sale_date_guard_uses_the_configured_salary_timezone(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-09-30 17:00:00', 'UTC'));
        $sales = User::factory()->sales()->create();
        $vehicle = Vehicle::factory()->create(['status' => 'reserved', 'reserved_at' => '2026-09-29 00:00:00', 'sold_price' => 100, 'buyer_name' => '買方', 'sales_agent_id' => $sales->id]);
        MoneyEntry::factory()->create(['vehicle_id' => $vehicle->id, 'category' => '訂金收入', 'direction' => 'income', 'amount' => 100, 'approval_status' => 'approved']);
        $this->actingAs($sales)->postJson("/api/vehicles/{$vehicle->id}/close-sale", ['sold_at' => '2026-10-01T00:00:00Z'])->assertUnprocessable();
        $this->postJson("/api/vehicles/{$vehicle->id}/close-sale", ['sold_at' => '2026-09-30T17:00:00Z'])->assertOk();
    }

    public function test_sale_date_boundaries_and_taipei_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 00:30:00', 'Asia/Taipei'));
        try {
            $admin = User::factory()->admin()->create();
            $sales = User::factory()->sales()->create();
            $vehicle = Vehicle::factory()->create(['status' => 'reserved', 'reserved_at' => '2026-09-29 12:00:00', 'sold_price' => 100, 'buyer_name' => '買方', 'sales_agent_id' => $sales->id]);
            MoneyEntry::factory()->create(['vehicle_id' => $vehicle->id, 'category' => '訂金收入', 'direction' => 'income', 'amount' => 100, 'approval_status' => 'approved']);
            $this->actingAs($admin)->postJson("/api/vehicles/{$vehicle->id}/close-sale", ['sold_at' => '2026-10-02'])->assertUnprocessable()->assertJsonValidationErrors('sold_at');
            $this->postJson("/api/vehicles/{$vehicle->id}/close-sale", ['sold_at' => '2026-09-28'])->assertUnprocessable()->assertJsonValidationErrors('sold_at');
            $this->actingAs($sales)->postJson("/api/vehicles/{$vehicle->id}/close-sale", ['sold_at' => '2026-09-30'])->assertUnprocessable()->assertJsonValidationErrors('sold_at');
            $this->postJson("/api/vehicles/{$vehicle->id}/close-sale", ['sold_at' => '2026-10-01'])->assertOk();
        } finally {
            Carbon::setTestNow();
        }
    }
}
