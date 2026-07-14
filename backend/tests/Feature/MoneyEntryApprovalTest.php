<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\MoneyEntryService;
use App\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Concerns\UsesCommissionAttributionFixtures;
use Tests\TestCase;

class MoneyEntryApprovalTest extends TestCase
{
    use RefreshDatabase;
    use UsesCommissionAttributionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommissionAttributionFixtures();
    }

    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        return parent::postJson($uri, $this->addCommissionAttributionFixtures($uri, $data), $headers, $options);
    }

    public function test_admin_created_manual_entry_is_approved_immediately(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $response = $this->actingAs($admin, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 5000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.approval_status', 'approved');
        $this->assertDatabaseHas('money_entries', ['id' => $response->json('data.id'), 'approval_status' => 'approved']);
    }

    public function test_manager_and_sales_created_manual_entry_is_pending(): void
    {
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        foreach (['manager', 'sales'] as $role) {
            $user = User::factory()->{$role}()->create(['is_active' => true]);

            Auth::forgetGuards();
            $response = $this->actingAs($user, 'web')->postJson('/api/money-entries', [
                'entry_date' => '2026-07-01',
                'direction' => 'income',
                'category' => '一般收入',
                'amount' => 5000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ]);

            $response->assertCreated();
            $response->assertJsonPath('data.approval_status', 'pending');
        }
    }

    public function test_vehicle_workflow_entry_created_by_admin_is_approved_immediately(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'listed']);

        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/reserve", [
            'buyer_name' => '王小明',
            'sold_price' => 500000,
            'deposit_amount' => 100000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        $this->assertDatabaseHas('money_entries', [
            'vehicle_id' => $vehicle->id,
            'category' => '訂金收入',
            'source_type' => 'vehicle_workflow',
            'approval_status' => 'approved',
        ]);
    }

    /**
     * 老闆身兼會計：manager/sales 建立的車輛快捷（購車付款/單車支出/收訂金/退款）與
     * 車輛流程（收訂金/收尾款）收支一律 pending，即使這些流程過去曾經直接 approved。
     */
    public function test_manager_and_sales_created_vehicle_workflow_deposit_is_pending(): void
    {
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        foreach (['manager', 'sales'] as $role) {
            $vehicle = Vehicle::factory()->create(['status' => 'listed']);
            $user = User::factory()->{$role}()->create(['is_active' => true]);

            // 此段說明相鄰程式碼的用途與預期行為。
            Auth::forgetGuards();
            $this->actingAs($user, 'web')->postJson("/api/vehicles/{$vehicle->id}/reserve", [
                'buyer_name' => '王小明',
                'sold_price' => 500000,
                'deposit_amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])->assertSuccessful();

            $this->assertDatabaseHas('money_entries', [
                'vehicle_id' => $vehicle->id,
                'category' => '訂金收入',
                'source_type' => 'vehicle_workflow',
                'approval_status' => 'pending',
            ]);
        }
    }

    public function test_manager_and_sales_created_vehicle_shortcut_expense_is_pending_admin_is_approved(): void
    {
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        foreach (['manager', 'sales'] as $role) {
            $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
            $user = User::factory()->{$role}()->create(['is_active' => true]);

            Auth::forgetGuards();
            $this->actingAs($user, 'web')->postJson("/api/vehicles/{$vehicle->id}/expense", [
                'category' => '維修支出',
                'amount' => 1000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => (string) Str::uuid(),
            ])->assertCreated()->assertJsonPath('data.approval_status', 'pending');
        }

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        Auth::forgetGuards();
        $this->actingAs($admin, 'web')->postJson("/api/vehicles/{$vehicle->id}/expense", [
            'category' => '維修支出',
            'amount' => 1000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('data.approval_status', 'approved');
    }

    public function test_admin_can_approve_and_reject_pending_vehicle_shortcut_and_workflow_entries(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $manager = User::factory()->manager()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicleForShortcut = Vehicle::factory()->create(['status' => 'preparing']);
        $vehicleForWorkflow = Vehicle::factory()->create(['status' => 'listed']);

        Auth::forgetGuards();
        $shortcut = $this->actingAs($manager, 'web')->postJson("/api/vehicles/{$vehicleForShortcut->id}/expense", [
            'category' => '維修支出',
            'amount' => 1000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $workflow = $this->actingAs($manager, 'web')->postJson("/api/vehicles/{$vehicleForWorkflow->id}/reserve", [
            'buyer_name' => '王小明',
            'sold_price' => 500000,
            'deposit_amount' => 100000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        $workflowEntryId = MoneyEntry::query()->where('vehicle_id', $vehicleForWorkflow->id)->where('category', '訂金收入')->firstOrFail()->id;

        Auth::forgetGuards();
        $this->actingAs($admin, 'web')
            ->patchJson("/api/money-entries/{$shortcut->json('data.id')}/approve")
            ->assertSuccessful()
            ->assertJsonPath('data.approval_status', 'approved');

        $this->actingAs($admin, 'web')
            ->patchJson("/api/money-entries/{$workflowEntryId}/reject")
            ->assertSuccessful()
            ->assertJsonPath('data.approval_status', 'rejected');
    }

    public function test_legacy_unknown_entry_cannot_be_approved_or_rejected(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $entry = MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'source_type' => 'legacy_unknown',
            'approval_status' => 'pending',
        ]);

        $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$entry->id}/approve")->assertStatus(422);
        $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$entry->id}/reject")->assertStatus(422);
    }

    public function test_pending_entry_excluded_from_balance_and_dashboard_and_vehicle_summary(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['type' => 'cash', 'opening_balance' => 0, 'is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'vehicle_id' => null,
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 10000,
            'source_type' => 'manual',
            'approval_status' => 'pending',
        ]);

        MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'vehicle_id' => $vehicle->id,
            'direction' => 'income',
            'category' => '其他單車收入',
            'amount' => 20000,
            'source_type' => 'manual',
            'approval_status' => 'rejected',
        ]);

        $balance = app(MoneyEntryService::class)->balanceForAccount($cashAccount->fresh());
        $this->assertSame(0, $balance);

        $response = $this->actingAs($admin, 'web')->getJson('/api/dashboard/summary');
        $response->assertOk();
        $response->assertJsonPath('monthly_income', 0);
        $response->assertJsonPath('cash_balance', 0);

        $summary = app(VehicleService::class)->financialSummary($vehicle->fresh());
        $this->assertSame(0, $summary['income_total']);
    }

    public function test_approved_entry_counts_toward_balance_after_admin_approval(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['opening_balance' => 0, 'is_active' => true]);
        $entry = MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 8000,
            'source_type' => 'manual',
            'approval_status' => 'pending',
        ]);

        $this->actingAs($admin, 'web')
            ->patchJson("/api/money-entries/{$entry->id}/approve")
            ->assertSuccessful()
            ->assertJsonPath('data.approval_status', 'approved');

        $this->assertDatabaseHas('money_entries', [
            'id' => $entry->id,
            'approval_status' => 'approved',
            'approved_by' => $admin->id,
        ]);

        $balance = app(MoneyEntryService::class)->balanceForAccount($cashAccount->fresh());
        $this->assertSame(8000, $balance);
    }

    public function test_rejected_entry_never_counts_toward_balance(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['opening_balance' => 0, 'is_active' => true]);
        $entry = MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 8000,
            'source_type' => 'manual',
            'approval_status' => 'pending',
        ]);

        $this->actingAs($admin, 'web')
            ->patchJson("/api/money-entries/{$entry->id}/reject")
            ->assertSuccessful()
            ->assertJsonPath('data.approval_status', 'rejected');

        $balance = app(MoneyEntryService::class)->balanceForAccount($cashAccount->fresh());
        $this->assertSame(0, $balance);
    }

    public function test_approve_and_reject_are_admin_only(): void
    {
        $manager = User::factory()->manager()->create(['is_active' => true]);
        $sales = User::factory()->sales()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $entry = MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 8000,
            'source_type' => 'manual',
            'approval_status' => 'pending',
        ]);

        $this->actingAs($manager, 'web')->patchJson("/api/money-entries/{$entry->id}/approve")->assertStatus(403);
        $this->actingAs($sales, 'web')->patchJson("/api/money-entries/{$entry->id}/reject")->assertStatus(403);
    }

    public function test_approved_or_rejected_status_cannot_be_reversed(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $approvedEntry = MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'source_type' => 'manual',
            'approval_status' => 'approved',
        ]);
        $rejectedEntry = MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'source_type' => 'manual',
            'approval_status' => 'rejected',
        ]);

        $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$approvedEntry->id}/approve")->assertStatus(422);
        $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$rejectedEntry->id}/approve")->assertStatus(422);
        $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$approvedEntry->id}/reject")->assertStatus(422);
    }

    public function test_approved_or_rejected_manual_entry_cannot_be_edited_or_deleted(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $approvedEntry = MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 1000,
            'source_type' => 'manual',
            'approval_status' => 'approved',
        ]);

        $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$approvedEntry->id}", [
            'entry_date' => '2026-07-02',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 2000,
            'cash_account_id' => $cashAccount->id,
        ])->assertStatus(422);

        $this->actingAs($admin, 'web')->deleteJson("/api/money-entries/{$approvedEntry->id}")->assertStatus(422);

        $this->assertDatabaseHas('money_entries', ['id' => $approvedEntry->id, 'amount' => 1000]);
    }

    public function test_frontend_cannot_forge_approval_status_on_create(): void
    {
        $manager = User::factory()->manager()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $response = $this->actingAs($manager, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 5000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => (string) Str::uuid(),
            'approval_status' => 'approved',
            'approved_by' => 999,
            'approved_at' => now()->toISOString(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.approval_status', 'pending');
        $this->assertDatabaseHas('money_entries', [
            'id' => $response->json('data.id'),
            'approval_status' => 'pending',
            'approved_by' => null,
        ]);
    }

    public function test_sales_cannot_update_or_delete_another_sales_users_pending_entry(): void
    {
        $author = User::factory()->sales()->create(['is_active' => true]);
        $otherSales = User::factory()->sales()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $entry = MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 1000,
            'source_type' => 'manual',
            'approval_status' => 'pending',
            'created_by' => $author->id,
        ]);

        $this->actingAs($otherSales, 'web')->patchJson("/api/money-entries/{$entry->id}", [
            'entry_date' => '2026-07-02',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 9999,
            'cash_account_id' => $cashAccount->id,
        ])->assertStatus(403);

        $this->actingAs($otherSales, 'web')->deleteJson("/api/money-entries/{$entry->id}")->assertStatus(403);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'amount' => 1000]);
    }

    public function test_manager_cannot_update_or_delete_another_users_pending_entry(): void
    {
        $author = User::factory()->sales()->create(['is_active' => true]);
        $manager = User::factory()->manager()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $entry = MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 1000,
            'source_type' => 'manual',
            'approval_status' => 'pending',
            'created_by' => $author->id,
        ]);

        $this->actingAs($manager, 'web')->patchJson("/api/money-entries/{$entry->id}", [
            'entry_date' => '2026-07-02',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 9999,
            'cash_account_id' => $cashAccount->id,
        ])->assertStatus(403);

        $this->actingAs($manager, 'web')->deleteJson("/api/money-entries/{$entry->id}")->assertStatus(403);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'amount' => 1000]);
    }

    public function test_sales_can_update_and_delete_own_pending_entry(): void
    {
        $sales = User::factory()->sales()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $entry = MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 1000,
            'source_type' => 'manual',
            'approval_status' => 'pending',
            'created_by' => $sales->id,
        ]);

        $this->actingAs($sales, 'web')->patchJson("/api/money-entries/{$entry->id}", [
            'entry_date' => '2026-07-02',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 1500,
            'cash_account_id' => $cashAccount->id,
        ])->assertSuccessful();

        $this->actingAs($sales, 'web')->deleteJson("/api/money-entries/{$entry->id}")->assertSuccessful();

        $this->assertDatabaseMissing('money_entries', ['id' => $entry->id]);
    }

    public function test_admin_can_update_and_delete_any_users_pending_entry(): void
    {
        $sales = User::factory()->sales()->create(['is_active' => true]);
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $entry = MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 1000,
            'source_type' => 'manual',
            'approval_status' => 'pending',
            'created_by' => $sales->id,
        ]);

        $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$entry->id}", [
            'entry_date' => '2026-07-02',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 1500,
            'cash_account_id' => $cashAccount->id,
        ])->assertSuccessful();

        $this->actingAs($admin, 'web')->deleteJson("/api/money-entries/{$entry->id}")->assertSuccessful();

        $this->assertDatabaseMissing('money_entries', ['id' => $entry->id]);
    }

    public function test_index_can_filter_by_approval_status(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        MoneyEntry::factory()->create(['cash_account_id' => $cashAccount->id, 'source_type' => 'manual', 'approval_status' => 'pending']);
        MoneyEntry::factory()->create(['cash_account_id' => $cashAccount->id, 'source_type' => 'manual', 'approval_status' => 'approved']);

        $response = $this->actingAs($admin, 'web')->getJson('/api/money-entries?approval_status=pending');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('pending', $response->json('data.0.approval_status'));
    }
}
