<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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

    public function test_manager_purchase_payment_is_pending_admin_purchase_payment_is_approved(): void
    {
        $manager = User::factory()->manager()->create(['is_active' => true]);
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicleForManager = Vehicle::factory()->create(['status' => 'preparing']);
        $vehicleForAdmin = Vehicle::factory()->create(['status' => 'preparing']);

        Auth::forgetGuards();
        $this->actingAs($manager, 'web')
            ->postJson("/api/vehicles/{$vehicleForManager->id}/purchase-payment", [
                'amount' => 300000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.approval_status', 'pending');

        Auth::forgetGuards();
        $this->actingAs($admin, 'web')
            ->postJson("/api/vehicles/{$vehicleForAdmin->id}/purchase-payment", [
                'amount' => 300000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.approval_status', 'approved');
    }

    public function test_sales_reported_expense_visible_to_self_but_not_other_sales_users_cost_amount(): void
    {
        $reporter = User::factory()->sales()->create(['is_active' => true]);
        $otherSales = User::factory()->sales()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        Auth::forgetGuards();
        $created = $this->actingAs($reporter, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/expense", [
                'category' => '維修支出',
                'amount' => 4500,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertCreated();

        // 上報者自己可在回應中看到金額與待審核狀態。
        $created->assertJsonPath('data.amount', 4500);
        $created->assertJsonPath('data.approval_status', 'pending');
        $entryId = $created->json('data.id');

        // 另一位 sales 透過一般收支列表看不到別人上報的成本金額（甚至看不到這筆紀錄，
        // 因為既非自己建立，也不是訂金/尾款/退款等銷售收款安全分類）。
        Auth::forgetGuards();
        $listResponse = $this->actingAs($otherSales, 'web')->getJson('/api/money-entries');
        $listResponse->assertOk();
        $ids = collect($listResponse->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($entryId));

        // 透過 show 端點直接以連號 id 查詢，非本人、非銷售收款安全分類的紀錄整筆拒絕
        // 存取（403），而不是回 200 再靠 Resource 遮蔽金額——否則 sales 仍可用連號 id
        // 枚舉出分類、對象、描述等 Resource 不會遮蔽的欄位。
        $showResponse = $this->actingAs($otherSales, 'web')->getJson("/api/money-entries/{$entryId}");
        $showResponse->assertStatus(403);
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
