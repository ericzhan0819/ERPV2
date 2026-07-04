<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.category', '一般收入');
        $this->assertDatabaseHas('money_entries', [
            'category' => '一般收入',
            'amount' => 5000,
            'vehicle_id' => null,
            'source_type' => 'manual',
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
            'idempotency_key' => (string) Str::uuid(),
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
            'idempotency_key' => (string) Str::uuid(),
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
            'idempotency_key' => (string) Str::uuid(),
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
            'idempotency_key' => (string) Str::uuid(),
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
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_idempotency_key_is_required(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $this->actingAs($user, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 5000,
            'cash_account_id' => $cashAccount->id,
        ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
    }

    public function test_same_idempotency_key_and_payload_only_creates_one_entry(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'entry_date' => '2026-07-01',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 5000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $first = $this->actingAs($user, 'web')->postJson('/api/money-entries', $payload)->assertCreated();
        $second = $this->actingAs($user, 'web')->postJson('/api/money-entries', $payload)->assertSuccessful();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, MoneyEntry::query()->where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_same_idempotency_key_with_different_payload_is_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 5000,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ])->assertCreated();

        $this->actingAs($user, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'income',
            'category' => '一般收入',
            'amount' => 9999,
            'cash_account_id' => $cashAccount->id,
            'idempotency_key' => $idempotencyKey,
        ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, MoneyEntry::query()->where('idempotency_key', $idempotencyKey)->count());
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
            'source_type' => 'manual',
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

    public function test_manual_vehicle_bound_entry_can_be_updated_and_deleted_before_vehicle_is_sold(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $entry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 1000,
            'source_type' => 'manual',
        ]);

        $this->actingAs($user, 'web')
            ->patchJson("/api/money-entries/{$entry->id}", [
                'entry_date' => '2026-07-02',
                'direction' => 'expense',
                'category' => '維修支出',
                'amount' => 2000,
                'cash_account_id' => $cashAccount->id,
                'vehicle_id' => $vehicle->id,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.amount', 2000);

        $this->actingAs($user, 'web')
            ->deleteJson("/api/money-entries/{$entry->id}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('money_entries', ['id' => $entry->id]);
    }

    public function test_manual_vehicle_bound_entry_cannot_be_updated_after_vehicle_is_sold(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'sold']);
        $entry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 1000,
            'source_type' => 'manual',
        ]);

        $this->actingAs($user, 'web')
            ->patchJson("/api/money-entries/{$entry->id}", [
                'entry_date' => '2026-07-02',
                'direction' => 'expense',
                'category' => '維修支出',
                'amount' => 2000,
                'cash_account_id' => $cashAccount->id,
                'vehicle_id' => $vehicle->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'amount' => 1000]);
    }

    public function test_manual_vehicle_bound_entry_cannot_be_deleted_after_vehicle_is_sold(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'sold']);
        $entry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 1000,
            'source_type' => 'manual',
        ]);

        $this->actingAs($user, 'web')
            ->deleteJson("/api/money-entries/{$entry->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id]);
    }

    public function test_cannot_create_vehicle_bound_entry_for_sold_vehicle(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'sold']);

        $this->actingAs($user, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 1000,
            'cash_account_id' => $cashAccount->id,
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('money_entries', 0);
    }

    public function test_cannot_create_vehicle_bound_entry_for_cancelled_vehicle(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'cancelled']);

        $this->actingAs($user, 'web')->postJson('/api/money-entries', [
            'entry_date' => '2026-07-01',
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 1000,
            'cash_account_id' => $cashAccount->id,
            'vehicle_id' => $vehicle->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('money_entries', 0);
    }

    public function test_general_manual_entry_without_vehicle_still_updatable_and_deletable(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $entry = MoneyEntry::factory()->create([
            'vehicle_id' => null,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '租金',
            'amount' => 1000,
            'source_type' => 'manual',
        ]);

        $this->actingAs($user, 'web')
            ->patchJson("/api/money-entries/{$entry->id}", [
                'entry_date' => '2026-07-02',
                'direction' => 'expense',
                'category' => '租金',
                'amount' => 1500,
                'cash_account_id' => $cashAccount->id,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.amount', 1500);

        $this->actingAs($user, 'web')
            ->deleteJson("/api/money-entries/{$entry->id}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('money_entries', ['id' => $entry->id]);
    }

    public function test_workflow_sourced_entry_cannot_be_updated_or_deleted_via_general_crud(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'reserved']);
        $entry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'income',
            'category' => '訂金收入',
            'amount' => 100000,
            'source_type' => 'vehicle_workflow',
        ]);

        $this->actingAs($user, 'web')
            ->patchJson("/api/money-entries/{$entry->id}", [
                'entry_date' => '2026-07-02',
                'direction' => 'income',
                'category' => '訂金收入',
                'amount' => 200000,
                'cash_account_id' => $cashAccount->id,
                'vehicle_id' => $vehicle->id,
            ])
            ->assertStatus(422);

        $this->actingAs($user, 'web')
            ->deleteJson("/api/money-entries/{$entry->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'amount' => 100000]);
    }

    /**
     * 模擬 source_type migration 已跑完（欄位存在，default manual）但
     * legacy row 沒有 durable provenance marker 的升級路徑：用 DB::table
     * insert 略過 Eloquent/Model 邏輯直接塞入不帶 source_type 的舊資料，
     * 讓它們落到 default manual，再手動執行 backfill migration 的
     * up()，驗證即使 legacy row 符合舊版不安全 heuristic 的分類與車輛
     * 狀態特徵，也必須維持 manual，不能被自動改成
     * vehicle_shortcut / vehicle_workflow，因為這些特徵不足以證明來源。
     */
    public function test_backfill_migration_keeps_legacy_entries_manual(): void
    {
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);

        $reservedVehicle = Vehicle::factory()->create([
            'status' => 'reserved',
            'sold_price' => 480000,
            'buyer_name' => '王小明',
        ]);
        $expenseVehicle = Vehicle::factory()->create(['status' => 'preparing']);

        $legacyRows = [
            // 舊版不安全 heuristic 會誤判成 vehicle_workflow，實際上是一般 CRUD 建立
            [
                'vehicle_id' => $reservedVehicle->id,
                'category' => '訂金收入',
                'direction' => 'income',
                'counterparty_name' => '王小明',
                'amount' => 100000,
            ],
            [
                'vehicle_id' => $reservedVehicle->id,
                'category' => '尾款收入',
                'direction' => 'income',
                'counterparty_name' => '王小明',
                'amount' => 380000,
            ],
            // 舊版不安全 heuristic 會誤判成 vehicle_shortcut，實際上是一般 CRUD 建立
            [
                'vehicle_id' => $expenseVehicle->id,
                'category' => '購車付款',
                'direction' => 'expense',
                'counterparty_name' => null,
                'amount' => 200000,
            ],
            [
                'vehicle_id' => $expenseVehicle->id,
                'category' => '維修支出',
                'direction' => 'expense',
                'counterparty_name' => null,
                'amount' => 3000,
            ],
            [
                'vehicle_id' => $expenseVehicle->id,
                'category' => '美容支出',
                'direction' => 'expense',
                'counterparty_name' => null,
                'amount' => 1500,
            ],
            [
                'vehicle_id' => $expenseVehicle->id,
                'category' => '代辦支出',
                'direction' => 'expense',
                'counterparty_name' => null,
                'amount' => 2500,
            ],
            [
                'vehicle_id' => $expenseVehicle->id,
                'category' => '拍場支出',
                'direction' => 'expense',
                'counterparty_name' => null,
                'amount' => 4000,
            ],
            [
                'vehicle_id' => $expenseVehicle->id,
                'category' => '其他支出',
                'direction' => 'expense',
                'counterparty_name' => null,
                'amount' => 1000,
            ],
            [
                'vehicle_id' => $expenseVehicle->id,
                'category' => '退款',
                'direction' => 'expense',
                'counterparty_name' => null,
                'amount' => 1000,
            ],
        ];

        $ids = [];

        foreach ($legacyRows as $index => $row) {
            $ids[$index] = DB::table('money_entries')->insertGetId([
                'vehicle_id' => $row['vehicle_id'],
                'cash_account_id' => $cashAccount->id,
                'entry_date' => '2026-06-01',
                'direction' => $row['direction'],
                'category' => $row['category'],
                'amount' => $row['amount'],
                'counterparty_name' => $row['counterparty_name'],
                'idempotency_key' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
                // source_type intentionally omitted -> falls back to column default 'manual'
            ]);
        }

        $migrationPath = database_path('migrations/2026_07_05_000001_backfill_money_entry_source_type.php');
        (require $migrationPath)->up();

        foreach ($legacyRows as $index => $row) {
            $this->assertSame(
                'manual',
                DB::table('money_entries')->where('id', $ids[$index])->value('source_type'),
                "category {$row['category']} on vehicle {$row['vehicle_id']} must remain manual without provenance"
            );
        }

        // API 層驗證：仍為 manual 的 legacy entry 可透過一般收支 CRUD 修改/刪除，
        // 只要車輛不是 sold/cancelled。
        $user = User::factory()->create(['is_active' => true]);

        $depositEntry = MoneyEntry::query()->whereKey($ids[0])->firstOrFail();
        $this->actingAs($user, 'web')
            ->patchJson("/api/money-entries/{$depositEntry->id}", [
                'entry_date' => '2026-06-02',
                'direction' => 'income',
                'category' => '訂金收入',
                'amount' => 110000,
                'cash_account_id' => $cashAccount->id,
                'vehicle_id' => $reservedVehicle->id,
            ])
            ->assertStatus(200);
        $this->assertDatabaseHas('money_entries', ['id' => $depositEntry->id, 'amount' => 110000]);

        $finalPaymentEntry = MoneyEntry::query()->whereKey($ids[1])->firstOrFail();
        $this->actingAs($user, 'web')
            ->deleteJson("/api/money-entries/{$finalPaymentEntry->id}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('money_entries', ['id' => $finalPaymentEntry->id]);

        $expenseEntry = MoneyEntry::query()->whereKey($ids[2])->firstOrFail();
        $this->actingAs($user, 'web')
            ->patchJson("/api/money-entries/{$expenseEntry->id}", [
                'entry_date' => '2026-06-02',
                'direction' => 'expense',
                'category' => '購車付款',
                'amount' => 210000,
                'cash_account_id' => $cashAccount->id,
                'vehicle_id' => $expenseVehicle->id,
            ])
            ->assertStatus(200);
        $this->assertDatabaseHas('money_entries', ['id' => $expenseEntry->id, 'amount' => 210000]);
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
