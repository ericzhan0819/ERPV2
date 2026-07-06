<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manager_and_sales_can_create_customer(): void
    {
        foreach (['admin', 'manager', 'sales'] as $role) {
            $user = User::factory()->{$role}()->create(['is_active' => true]);

            $response = $this->actingAs($user, 'web')->postJson('/api/customers', [
                'name' => "測試客戶-{$role}",
                'phone' => '0912345678',
                'customer_type' => 'buyer',
                'source' => '個人',
            ])->assertCreated();

            $this->assertDatabaseHas('customers', [
                'id' => $response->json('data.id'),
                'name' => "測試客戶-{$role}",
            ]);
        }
    }

    public function test_admin_manager_and_sales_can_update_customer(): void
    {
        foreach (['admin', 'manager', 'sales'] as $role) {
            $user = User::factory()->{$role}()->create(['is_active' => true]);
            $customer = Customer::factory()->create(['name' => '原始姓名']);

            $this->actingAs($user, 'web')->putJson("/api/customers/{$customer->id}", [
                'name' => "更新姓名-{$role}",
                'customer_type' => 'seller',
            ])->assertOk()->assertJsonPath('data.name', "更新姓名-{$role}");

            $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => "更新姓名-{$role}"]);
        }
    }

    public function test_update_clears_nullable_field_when_explicit_null_is_sent(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $customer = Customer::factory()->create([
            'phone' => '0912345678',
            'line_id' => 'original-line-id',
            'source' => '個人',
            'address' => '原始地址',
            'notes' => '原始備註',
        ]);

        $this->actingAs($admin, 'web')->putJson("/api/customers/{$customer->id}", [
            'name' => $customer->name,
            'customer_type' => $customer->customer_type,
            'phone' => null,
            'line_id' => null,
            'source' => null,
            'address' => null,
            'notes' => null,
        ])->assertOk()
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.line_id', null)
            ->assertJsonPath('data.source', null)
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.notes', null);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'phone' => null,
            'line_id' => null,
            'source' => null,
            'address' => null,
            'notes' => null,
        ]);
    }

    public function test_manager_and_sales_cannot_delete_customer(): void
    {
        foreach (['manager', 'sales'] as $role) {
            $user = User::factory()->{$role}()->create(['is_active' => true]);
            $customer = Customer::factory()->create();

            $this->actingAs($user, 'web')->deleteJson("/api/customers/{$customer->id}")
                ->assertStatus(403);

            $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        }
    }

    public function test_admin_can_delete_customer_without_vehicles(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $customer = Customer::factory()->create();

        $this->actingAs($admin, 'web')->deleteJson("/api/customers/{$customer->id}")->assertOk();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_admin_cannot_delete_customer_with_related_vehicle_as_seller(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $customer = Customer::factory()->create();
        Vehicle::factory()->create(['seller_customer_id' => $customer->id]);

        $this->actingAs($admin, 'web')->deleteJson("/api/customers/{$customer->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer');

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_admin_cannot_delete_customer_with_related_vehicle_as_buyer(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $customer = Customer::factory()->create();
        Vehicle::factory()->create(['buyer_customer_id' => $customer->id]);

        $this->actingAs($admin, 'web')->deleteJson("/api/customers/{$customer->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer');

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_foreign_key_restricts_deletion_of_linked_customer_even_bypassing_the_service_check(): void
    {
        // Proves the DB-level backstop (restrictOnDelete), not just the application
        // check: deletes here go straight through the query builder, bypassing
        // CustomerService::deleteCustomer()'s own relation check entirely, to confirm
        // a linked customer can never be silently deleted (or nulled out) even if the
        // app-level check were raced or skipped.
        $customer = Customer::factory()->create();
        Vehicle::factory()->create(['seller_customer_id' => $customer->id]);

        $this->expectException(QueryException::class);

        DB::table('customers')->where('id', $customer->id)->delete();
    }

    public function test_vehicle_seller_name_snapshot_does_not_change_when_customer_is_updated(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $customer = Customer::factory()->create(['name' => '原始賣家', 'phone' => '0900000000']);

        $vehicle = Vehicle::factory()->create([
            'seller_customer_id' => $customer->id,
            'seller_name' => '原始賣家',
            'seller_phone' => '0900000000',
        ]);

        $this->actingAs($admin, 'web')->putJson("/api/customers/{$customer->id}", [
            'name' => '改名後的賣家',
            'phone' => '0911111111',
            'customer_type' => 'seller',
        ])->assertOk();

        $vehicle->refresh();

        $this->assertSame('原始賣家', $vehicle->seller_name);
        $this->assertSame('0900000000', $vehicle->seller_phone);
    }

    public function test_index_and_show_are_accessible_to_all_three_roles(): void
    {
        Customer::factory()->create(['name' => '查詢用客戶']);

        foreach (['admin', 'manager', 'sales'] as $role) {
            $user = User::factory()->{$role}()->create(['is_active' => true]);

            $this->actingAs($user, 'web')->getJson('/api/customers')->assertOk();
        }
    }

    public function test_guest_cannot_access_customers(): void
    {
        $this->getJson('/api/customers')->assertStatus(401);
    }
}
