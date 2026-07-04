<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_general_income_entry_without_vehicle(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 5000,
            'cash_account_id' => $cashAccount->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.category', '一般收入');
        $this->assertDatabaseHas('money_entries', [
            'category' => '一般收入',
            'amount' => 5000,
            'vehicle_id' => null,
        ]);
    }

    public function test_general_only_category_rejects_vehicle_id(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();

        $this->actingAs($user, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'expense',
            'category' => '租金',
            'amount' => 5000,
            'cash_account_id' => $cashAccount->id,
            'vehicle_id' => $vehicle->id,
        ])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
    }

    public function test_vehicle_required_category_rejects_missing_vehicle_id(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 5000,
            'cash_account_id' => $cashAccount->id,
        ])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
    }

    public function test_category_direction_mismatch_is_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'expense',
            'category' => '一般收入',
            'amount' => 5000,
            'cash_account_id' => $cashAccount->id,
        ])->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_cannot_create_entry_with_disabled_cash_account(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => false]);

        $this->actingAs($user, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 5000,
            'cash_account_id' => $cashAccount->id,
        ])->assertStatus(422)->assertJsonValidationErrors('cash_account_id');
    }

    public function test_amount_must_be_positive(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 0,
            'cash_account_id' => $cashAccount->id,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_index_supports_filters_and_pagination(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccountA = CashAccount::factory()->create(['is_active' => true]);
        $cashAccountB = CashAccount::factory()->create(['is_active' => true]);

        MoneyEntry::factory()->create(['cash_account_id' => $cashAccountA->id, 'direction' => 'income', 'category' => '一般收入']);
        MoneyEntry::factory()->create(['cash_account_id' => $cashAccountB->id, 'direction' => 'expense', 'category' => '租金']);

        $response = $this->actingAs($user, 'web')->getJson('/api/money-entries?direction=expense');

        $response->assertSuccessful();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('租金', $response->json('data.0.category'));
    }

    public function test_update_and_delete_entry(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $entry = MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 1000,
        ]);

        $this->actingAs($user, 'web')
            ->patchJson("/api/money-entries/{$entry->id}", [
                'entry_date' => '2026-07-02',
                'direction' => 'income',
                'category' => '一般收入',
                'amount' => 2000,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.amount', 2000);

        $this->actingAs($user, 'web')
            ->deleteJson("/api/money-entries/{$entry->id}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('money_entries', ['id' => $entry->id]);
    }

    public function test_dashboard_and_account_balance_reflect_entries(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['type' => 'cash', 'opening_balance' => 10000, 'is_active' => true]);

        MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 5000,
        ]);
        MoneyEntry::factory()->create([
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '租金',
            'amount' => 2000,
        ]);

        $balance = app(\App\Services\MoneyEntryService::class)->balanceForAccount($cashAccount->fresh());
        $this->assertSame(13000, $balance);

        $response = $this->actingAs($user, 'web')->getJson('/api/dashboard/summary');
        $response->assertSuccessful();
        $response->assertJsonPath('cash_balance', 13000);
    }
}
