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
use Tests\TestCase;

class MoneyEntryReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(MoneyEntry $entry): array
    {
        return [
            'entry_date' => $entry->entry_date->toDateString(),
            'direction' => $entry->direction,
            'category' => $entry->category,
            'amount' => $entry->amount,
            'cash_account_id' => $entry->cash_account_id,
        ];
    }

    public function test_oversized_pending_entries_cannot_be_approved_but_can_be_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');
        foreach (['manual', 'vehicle_shortcut', 'vehicle_workflow'] as $source) {
            $entry = MoneyEntry::factory()->create([
                'source_type' => $source, 'approval_status' => 'pending', 'amount' => 1000000000000,
            ]);
            $payload = ['expected_review_token' => $entry->reviewToken()];
            $this->patchJson("/api/money-entries/{$entry->id}/approve", $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('amount');
            $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'approval_status' => 'pending', 'approved_by' => null]);
            $this->patchJson("/api/money-entries/{$entry->id}/reject", $payload)
                ->assertOk()->assertJsonPath('data.approval_status', 'rejected');
        }
        $entry = MoneyEntry::factory()->create(['source_type' => 'manual', 'approval_status' => 'pending', 'amount' => 999999999999]);
        $this->patchJson("/api/money-entries/{$entry->id}/approve", ['expected_review_token' => $entry->reviewToken()])->assertOk();
    }

    public function test_sales_cannot_create_or_change_a_manual_entry_into_a_purchase_payment(): void
    {
        $sales = User::factory()->sales()->create();
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $entry = MoneyEntry::factory()->create([
            'source_type' => 'manual', 'approval_status' => 'pending', 'created_by' => $sales->id,
            'category' => '維修支出', 'direction' => 'expense', 'vehicle_id' => $vehicle->id,
        ]);
        $payload = [...$this->payload($entry), 'category' => '購車付款', 'vehicle_id' => $vehicle->id, 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($sales, 'web')->postJson('/api/money-entries', $payload)->assertForbidden();
        $this->patchJson("/api/money-entries/{$entry->id}", $payload)->assertForbidden();
        $this->assertSame('維修支出', $entry->fresh()->category);
        $this->assertDatabaseCount('money_entries', 1);
        $this->patchJson("/api/money-entries/{$entry->id}", [...$payload, 'category' => '維修支出'])->assertOk();

        foreach (['admin', 'manager'] as $role) {
            Auth::forgetGuards();
            $this->actingAs(User::factory()->{$role}()->create(), 'web')->postJson('/api/money-entries', [
                ...$payload, 'idempotency_key' => (string) Str::uuid(),
            ])->assertCreated()->assertJsonPath('data.approval_status', $role === 'admin' ? 'approved' : 'pending');
        }
    }

    public function test_existing_pending_vehicle_cost_can_be_approved_after_sale_and_account_deactivation(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'sold']);
        $account = CashAccount::factory()->create(['is_active' => false]);
        $entry = MoneyEntry::factory()->create([
            'source_type' => 'manual', 'approval_status' => 'pending', 'category' => '維修支出',
            'direction' => 'expense', 'amount' => 8000, 'vehicle_id' => $vehicle->id, 'cash_account_id' => $account->id,
        ]);
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->patchJson("/api/money-entries/{$entry->id}/approve", ['expected_review_token' => $entry->reviewToken()])
            ->assertOk()->assertJsonPath('data.approval_status', 'approved');
        $this->assertSame(-8000, app(MoneyEntryService::class)->balanceForAccount($account));
        $this->assertSame(-8000, app(VehicleService::class)->financialSummary($vehicle)['gross_profit']);
    }

    public function test_omitted_vehicle_uses_persisted_binding_and_explicit_null_detaches(): void
    {
        $manager = User::factory()->manager()->create();
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $entry = MoneyEntry::factory()->create([
            'source_type' => 'manual', 'approval_status' => 'pending',
            'created_by' => $manager->id, 'vehicle_id' => $vehicle->id,
            'direction' => 'expense', 'category' => '其他支出', 'amount' => 8000,
        ]);
        $original = $entry->fresh()->getRawOriginal();
        $this->actingAs($manager, 'web')->patchJson("/api/money-entries/{$entry->id}", [
            ...$this->payload($entry), 'category' => '租金',
        ])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
        $this->assertSame($original, $entry->fresh()->getRawOriginal());

        $this->patchJson("/api/money-entries/{$entry->id}", [
            ...$this->payload($entry), 'category' => '維修支出',
        ])->assertOk()->assertJsonPath('data.vehicle_id', $vehicle->id);

        $this->patchJson("/api/money-entries/{$entry->id}", [
            ...$this->payload($entry), 'category' => '租金', 'vehicle_id' => null,
        ])->assertOk()->assertJsonPath('data.vehicle_id', null);
    }

    public function test_reviews_require_current_content_even_when_updated_in_the_same_second(): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $account = CashAccount::factory()->create();
        foreach (['approve', 'reject'] as $action) {
            foreach (['amount' => 900000, 'cash_account_id' => $account->id, 'category' => '租金', 'description' => '已變更'] as $field => $value) {
                $entry = MoneyEntry::factory()->create([
                    'source_type' => 'manual', 'approval_status' => 'pending',
                    'created_by' => $manager->id, 'amount' => 1000, 'direction' => 'expense', 'category' => '其他支出',
                ]);
                Auth::forgetGuards();
                $token = $this->actingAs($admin, 'web')->getJson("/api/money-entries/{$entry->id}")
                    ->assertOk()->json('data.review_token');
                Auth::forgetGuards();
                $this->actingAs($manager, 'web')->patchJson("/api/money-entries/{$entry->id}", [
                    ...$this->payload($entry), $field => $value,
                ])->assertOk()->assertJsonMissingPath('data.review_token');
                $this->assertTrue($entry->updated_at->equalTo($entry->fresh()->updated_at));
                Auth::forgetGuards();
                $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$entry->id}/{$action}", [
                    'expected_review_token' => $token,
                ])->assertConflict();
                $this->assertDatabaseHas('money_entries', [
                    'id' => $entry->id, 'approval_status' => 'pending', 'approved_by' => null, 'approved_at' => null,
                ]);
                $freshToken = $this->getJson("/api/money-entries/{$entry->id}")->json('data.review_token');
                $this->patchJson("/api/money-entries/{$entry->id}/{$action}", [
                    'expected_review_token' => $freshToken,
                ])->assertOk()->assertJsonPath('data.approval_status', $action === 'approve' ? 'approved' : 'rejected');
            }
        }
    }

    public function test_update_response_token_accepts_numeric_string_identifiers(): void
    {
        $admin = User::factory()->admin()->create();
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $entry = MoneyEntry::factory()->create(['source_type' => 'manual', 'approval_status' => 'pending']);
        $response = $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$entry->id}", [
            ...$this->payload($entry), 'cash_account_id' => (string) $entry->cash_account_id,
            'vehicle_id' => (string) $vehicle->id, 'category' => '其他單車收入',
        ])->assertOk();
        $token = $response->json('data.review_token');
        $this->assertSame($entry->fresh()->reviewToken(), $token);
        $this->patchJson("/api/money-entries/{$entry->id}/approve", ['expected_review_token' => $token])->assertOk();
    }

    public function test_bank_monthly_total_overflow_is_rejected_for_all_dashboard_roles(): void
    {
        $account = CashAccount::factory()->create(['type' => 'bank']);
        foreach ([PHP_INT_MAX, 1] as $amount) {
            MoneyEntry::factory()->create(['cash_account_id' => $account->id, 'amount' => $amount]);
        }
        foreach (['admin', 'manager', 'sales'] as $role) {
            Auth::forgetGuards();
            $this->actingAs(User::factory()->{$role}()->create(), 'web')
                ->getJson('/api/dashboard/summary')->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
    }

    public function test_historical_cash_trend_does_not_overflow_when_current_balance_is_safe(): void
    {
        $this->travelTo(now()->day(15));
        $account = CashAccount::factory()->create(['type' => 'cash', 'opening_balance' => 1]);
        MoneyEntry::factory()->create([
            'cash_account_id' => $account->id, 'amount' => PHP_INT_MAX, 'entry_date' => now()->subDays(2)->toDateString(),
        ]);
        MoneyEntry::factory()->create([
            'cash_account_id' => $account->id, 'amount' => PHP_INT_MAX, 'direction' => 'expense',
            'entry_date' => now()->subDay()->toDateString(),
        ]);
        $this->assertSame(1, app(MoneyEntryService::class)->balanceForType('cash'));
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->getJson('/api/dashboard/summary')->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_vehicle_and_dashboard_gross_profit_aggregates_reject_overflow(): void
    {
        $vehicle = Vehicle::factory()->create(['status' => 'sold', 'sold_at' => now()]);
        $account = CashAccount::factory()->create(['type' => 'bank']);
        foreach ([PHP_INT_MAX, 1] as $amount) {
            MoneyEntry::factory()->create([
                'cash_account_id' => $account->id, 'vehicle_id' => $vehicle->id, 'amount' => $amount,
                'entry_date' => now()->subMonths(2)->toDateString(),
            ]);
        }
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->getJson('/api/dashboard/summary')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->getJson("/api/vehicles/{$vehicle->id}")->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_missing_or_malformed_review_token_fails_closed(): void
    {
        $entry = MoneyEntry::factory()->create(['source_type' => 'manual', 'approval_status' => 'pending']);
        $this->actingAs(User::factory()->admin()->create(), 'web');
        foreach (['approve', 'reject'] as $action) {
            foreach ([[], ['expected_review_token' => 'bad']] as $payload) {
                $this->patchJson("/api/money-entries/{$entry->id}/{$action}", $payload)
                    ->assertUnprocessable()->assertJsonValidationErrors('expected_review_token');
            }
        }
        $this->assertSame('pending', $entry->fresh()->approval_status);
    }

    public function test_money_and_opening_balance_limits_apply_to_create_and_update(): void
    {
        $admin = User::factory()->admin()->create();
        $entry = MoneyEntry::factory()->create(['source_type' => 'manual', 'approval_status' => 'pending']);
        $this->actingAs($admin, 'web');
        $payload = [...$this->payload($entry), 'amount' => 1000000000000, 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/money-entries', $payload)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->patchJson("/api/money-entries/{$entry->id}", $payload)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $account = $entry->cashAccount;
        $payload = ['name' => '測試', 'type' => 'cash', 'opening_balance' => 1000000000000];
        $this->postJson('/api/cash-accounts', $payload)->assertUnprocessable()->assertJsonValidationErrors('opening_balance');
        $this->patchJson("/api/cash-accounts/{$account->id}", $payload)->assertUnprocessable()->assertJsonValidationErrors('opening_balance');
        $this->postJson('/api/money-entries', [
            ...$this->payload($entry), 'amount' => 999999999999, 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->postJson('/api/cash-accounts', [...$payload, 'opening_balance' => 999999999999])->assertCreated();
    }

    public function test_shortcuts_and_workflows_reject_oversized_money(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $vehicle = Vehicle::factory()->create(['status' => 'reserved']);
        $account = CashAccount::factory()->create();
        foreach (['purchase-payment', 'expense', 'deposit', 'refund', 'final-payment'] as $endpoint) {
            $this->postJson("/api/vehicles/{$vehicle->id}/{$endpoint}", [
                'amount' => 1000000000000, 'cash_account_id' => $account->id,
                'category' => '維修支出', 'idempotency_key' => (string) Str::uuid(),
            ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->postJson("/api/vehicles/{$vehicle->id}/reserve", [
            'deposit_amount' => 1000000000000,
        ])->assertUnprocessable()->assertJsonValidationErrors('deposit_amount');
        $this->postJson('/api/vehicles', [
            'initial_purchase_payment' => ['amount' => 1000000000000],
        ])->assertUnprocessable()->assertJsonValidationErrors('initial_purchase_payment.amount');
        $this->assertDatabaseCount('money_entries', 0);
    }

    public function test_existing_overflowing_balance_returns_validation_error_instead_of_500(): void
    {
        $account = CashAccount::factory()->create(['type' => 'cash', 'opening_balance' => 1]);
        MoneyEntry::factory()->create(['cash_account_id' => $account->id, 'amount' => PHP_INT_MAX]);
        foreach (['admin', 'sales'] as $role) {
            Auth::forgetGuards();
            $this->actingAs(User::factory()->{$role}()->create(), 'web')
                ->getJson('/api/dashboard/summary')->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        Auth::forgetGuards();
        $this->actingAs(User::factory()->admin()->create(), 'web')
            ->getJson('/api/cash-accounts/balances')->assertUnprocessable()->assertJsonValidationErrors('amount');
        MoneyEntry::factory()->create(['cash_account_id' => $account->id, 'amount' => 1]);
        $this->getJson('/api/cash-accounts/balances')->assertUnprocessable();
    }

    public function test_offsetting_large_totals_preserve_exact_integer_balance(): void
    {
        $account = CashAccount::factory()->create(['type' => 'cash', 'opening_balance' => 1]);
        MoneyEntry::factory()->create(['cash_account_id' => $account->id, 'amount' => PHP_INT_MAX]);
        MoneyEntry::factory()->create(['cash_account_id' => $account->id, 'amount' => PHP_INT_MAX, 'direction' => 'expense']);
        $service = app(MoneyEntryService::class);
        $this->assertSame(1, $service->balanceForAccount($account));
        $this->assertSame(1, $service->balanceForType('cash'));
    }
}
