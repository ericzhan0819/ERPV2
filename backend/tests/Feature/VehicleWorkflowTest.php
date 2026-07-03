<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function test_final_payment_replays_when_retry_omits_entry_date_even_though_first_call_set_it(): void
    {
        // New semantics: omitting entry_date on retry means "replay whatever date was
        // originally stored", not "change the date to null/today". Only an explicitly
        // supplied, mismatching entry_date should be rejected (see the "different date
        // explicitly supplied" test below).
        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
                'entry_date' => '2026-07-01',
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSuccessful()
            ->assertJsonPath('warning', null);

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
        $this->assertSame('2026-07-01', MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->first()
            ->entry_date
            ->toDateString());
    }

    public function test_final_payment_rejects_same_idempotency_key_when_explicit_entry_date_differs(): void
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
                'entry_date' => '2026-07-01',
            ])
            ->assertSuccessful();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
                'entry_date' => '2026-07-02',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_replays_across_midnight_when_entry_date_always_omitted(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-03 23:59:00'));

        try {
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

            Carbon::setTestNow(Carbon::parse('2026-07-04 00:01:00'));

            $this->actingAs($user, 'web')
                ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
                ->assertSuccessful()
                ->assertJsonPath('warning', null);

            $this->assertSame(1, MoneyEntry::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('category', '尾款收入')
                ->count());
            $this->assertSame('2026-07-03', MoneyEntry::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('category', '尾款收入')
                ->first()
                ->entry_date
                ->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_final_payment_replays_when_retry_supplies_the_stored_entry_date(): void
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

        $storedEntryDate = MoneyEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first()
            ->entry_date
            ->toDateString();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
                'entry_date' => $storedEntryDate,
            ])
            ->assertSuccessful()
            ->assertJsonPath('warning', null);

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_rejects_when_retry_supplies_a_different_entry_date_than_stored(): void
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

        $storedEntryDate = MoneyEntry::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first()
            ->entry_date
            ->toDateString();
        $differentDate = Carbon::parse($storedEntryDate)->addDay()->toDateString();

        $this->actingAs($user, 'web')
            ->postJson("/api/vehicles/{$vehicle->id}/final-payment", [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
                'entry_date' => $differentDate,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    public function test_final_payment_replays_when_entry_date_omitted_then_explicitly_matches_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-03 10:00:00'));

        try {
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
                    'amount' => 380000,
                    'cash_account_id' => $cashAccount->id,
                    'idempotency_key' => $idempotencyKey,
                    'entry_date' => '2026-07-03',
                ])
                ->assertSuccessful()
                ->assertJsonPath('warning', null);

            $this->assertSame(1, MoneyEntry::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('category', '尾款收入')
                ->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Covers only the duplicate-key error recovery path (rollback -> fresh read -> replay),
     * driven by injecting a duplicate insert before save() that is committed independently
     * of the current (losing) transaction — simulating a concurrent request that already
     * committed its winning row. It does NOT exercise the MySQL REPEATABLE READ
     * stale-snapshot scenario, since SQLite has no second connection/transaction to race
     * against — see the MySQL-only test below for that.
     */
    public function test_final_payment_replays_after_duplicate_key_error_from_same_connection_insert(): void
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

        $raced = false;
        MoneyEntry::creating(function (MoneyEntry $entry) use (&$raced, $idempotencyKey, $user) {
            if ($raced || $entry->idempotency_key !== $idempotencyKey) {
                return;
            }

            $raced = true;

            // Commit the currently-open transaction early so the "winning" insert below is
            // durable and survives the rollback that will happen once this request's own
            // insert fails on the unique constraint, then reopen a transaction so the rest
            // of the current call proceeds as normal. This mimics a genuinely concurrent
            // request that committed before our insert executed.
            DB::commit();

            DB::table('money_entries')->insert([
                'vehicle_id' => $entry->vehicle_id,
                'cash_account_id' => $entry->cash_account_id,
                'entry_date' => $entry->entry_date,
                'direction' => $entry->direction,
                'category' => $entry->category,
                'amount' => $entry->amount,
                'counterparty_name' => $entry->counterparty_name,
                'description' => $entry->description,
                'idempotency_key' => $entry->idempotency_key,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::beginTransaction();
        });

        try {
            $this->actingAs($user, 'web')
                ->postJson("/api/vehicles/{$vehicle->id}/final-payment", $payload)
                ->assertSuccessful()
                ->assertJsonPath('warning', null);
        } finally {
            MoneyEntry::flushEventListeners();
        }

        $this->assertSame(1, MoneyEntry::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('category', '尾款收入')
            ->count());
    }

    /**
     * MySQL-only: reproduces the REPEATABLE READ stale-snapshot problem that the SQLite
     * duplicate-key test above cannot reach (SQLite has no second connection/transaction
     * to race against). Uses two independent DB connections against a real MySQL instance
     * and explicit transaction ordering as a deterministic barrier (no sleep, no forking):
     *
     *  1. Connection B opens a transaction and takes its REPEATABLE READ snapshot by
     *     reading for the idempotency_key (finds nothing yet).
     *  2. Connection A (the default connection, i.e. what the real request/service uses)
     *     runs the actual recordFinalPayment() call end-to-end and commits the winning row.
     *  3. We assert B's still-open transaction cannot see A's committed row — this is the
     *     exact staleness that made the old "re-SELECT inside the same transaction" recovery
     *     path broken in production.
     *  4. After rolling B back and re-reading, the row becomes visible — this is what
     *     VehicleService::replayRacedFinalPaymentAfterRollback() relies on: rollback the
     *     failed transaction, then do a fresh read in a new transaction.
     *
     * This suite runs under SQLite by default (see phpunit.xml), so this test is skipped
     * unless it is executed against a real MySQL connection, e.g.:
     *   DB_CONNECTION=mysql DB_DATABASE=erpv2_testing php artisan test --filter=mysql_repeatable_read
     * Point DB_DATABASE at a disposable schema — do not run this against a shared dev database.
     */
    public function test_final_payment_replay_survives_mysql_repeatable_read_stale_snapshot_across_two_connections(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                '此測試需要以 MySQL 作為預設連線執行（例如 DB_CONNECTION=mysql 搭配獨立可拋棄的測試資料庫），'.
                '用來重現 REPEATABLE READ 下 stale snapshot 的兩個連線競態情境；SQLite 無法重現此問題，故略過。'
            );
        }

        config(['database.connections.mysql_race' => config('database.connections.mysql')]);

        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $idempotencyKey = (string) Str::uuid();

        $connB = DB::connection('mysql_race');

        try {
            $connB->beginTransaction();
            $staleBeforeCommit = $connB->table('money_entries')->where('idempotency_key', $idempotencyKey)->first();
            $this->assertNull($staleBeforeCommit);

            app(VehicleService::class)->recordFinalPayment($vehicle, [
                'amount' => 380000,
                'cash_account_id' => $cashAccount->id,
                'idempotency_key' => $idempotencyKey,
            ], $user->id);

            $staleAfterCommit = $connB->table('money_entries')->where('idempotency_key', $idempotencyKey)->first();
            $this->assertNull(
                $staleAfterCommit,
                'REPEATABLE READ 快照下，B 交易內不應看到 A 交易新提交的資料，這正是需要 rollback 後開新交易重讀的原因'
            );

            $connB->rollBack();

            $freshRead = $connB->table('money_entries')->where('idempotency_key', $idempotencyKey)->first();
            $this->assertNotNull($freshRead, 'rollback 後於新交易重讀，應能看到贏家已提交的資料');
        } finally {
            if ($connB->transactionLevel() > 0) {
                $connB->rollBack();
            }
            DB::purge('mysql_race');
        }

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
