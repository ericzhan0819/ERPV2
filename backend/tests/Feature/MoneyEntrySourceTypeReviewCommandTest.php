<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MoneyEntrySourceTypeReviewCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeLegacyUnknownEntry(): MoneyEntry
    {
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        return MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 1000,
            'source_type' => 'legacy_unknown',
        ]);
    }

    private function makeVehicleShortcutEntry(): MoneyEntry
    {
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        return MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '購車付款',
            'amount' => 2000,
            'source_type' => 'vehicle_shortcut',
        ]);
    }

    /**
     * 模擬 2026_07_05_000003 migration 在某個環境執行當下，把既有 row 拍照
     * 進 candidate 表，且尚未有任何人工 review 紀錄。
     */
    private function seedUnresolvedCandidate(MoneyEntry $entry, string $reason): void
    {
        DB::table('money_entry_source_type_review_candidates')->insert([
            'money_entry_id' => $entry->id,
            'captured_source_type' => $entry->source_type,
            'candidate_reason' => $reason,
            'money_entry_snapshot' => json_encode($entry->toArray()),
            'resolved_at' => null,
            'resolved_by' => null,
            'resolution_review_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_dry_run_does_not_change_database_or_write_review_log(): void
    {
        Storage::fake('local');
        $entry = $this->makeLegacyUnknownEntry();

        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $entry->id,
            '--to' => 'manual',
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'source_type' => 'legacy_unknown']);
        $this->assertSame(0, DB::table('money_entry_source_type_reviews')->count());
        Storage::disk('local')->assertDirectoryEmpty('money-entry-source-type-backups');
    }

    public function test_non_dry_run_without_approver_fails(): void
    {
        $entry = $this->makeLegacyUnknownEntry();

        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $entry->id,
            '--to' => 'manual',
            '--reason' => '人工核對確認為一般收支',
        ])->assertExitCode(1);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'source_type' => 'legacy_unknown']);
    }

    public function test_non_dry_run_without_reason_fails(): void
    {
        $entry = $this->makeLegacyUnknownEntry();

        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $entry->id,
            '--to' => 'manual',
            '--approver' => '王小美',
        ])->assertExitCode(1);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'source_type' => 'legacy_unknown']);
    }

    public function test_non_dry_run_with_valid_input_updates_writes_review_log_and_backup(): void
    {
        Storage::fake('local');
        $entry = $this->makeLegacyUnknownEntry();

        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $entry->id,
            '--to' => 'manual',
            '--approver' => '王小美',
            '--reason' => '人工核對確認為一般收支',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'source_type' => 'manual']);

        $review = DB::table('money_entry_source_type_reviews')->where('money_entry_id', $entry->id)->first();
        $this->assertNotNull($review);
        $this->assertSame('legacy_unknown', $review->previous_source_type);
        $this->assertSame('manual', $review->new_source_type);
        $this->assertSame('王小美', $review->approver);
        $this->assertNotNull($review->backup_path);
        $this->assertNotNull($review->money_entry_snapshot);

        $files = Storage::disk('local')->files('money-entry-source-type-backups');
        $this->assertCount(1, $files);
    }

    public function test_rerunning_same_batch_to_same_target_is_idempotent(): void
    {
        Storage::fake('local');
        $entry = $this->makeLegacyUnknownEntry();

        $params = [
            '--ids' => (string) $entry->id,
            '--to' => 'manual',
            '--approver' => '王小美',
            '--reason' => '人工核對確認為一般收支',
        ];

        $this->artisan('money-entries:source-type-review', $params)->assertExitCode(0);
        $this->artisan('money-entries:source-type-review', $params)->assertExitCode(0);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'source_type' => 'manual']);
        $this->assertSame(1, DB::table('money_entry_source_type_reviews')->where('money_entry_id', $entry->id)->count());
    }

    public function test_missing_id_fails_without_partial_update(): void
    {
        $entry = $this->makeLegacyUnknownEntry();
        $missingId = $entry->id + 999;

        $this->artisan('money-entries:source-type-review', [
            '--ids' => "{$entry->id},{$missingId}",
            '--to' => 'manual',
            '--approver' => '王小美',
            '--reason' => '人工核對確認為一般收支',
        ])->assertExitCode(1);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'source_type' => 'legacy_unknown']);
    }

    public function test_gate_fails_when_legacy_unknown_entries_exist(): void
    {
        $this->makeLegacyUnknownEntry();

        $this->artisan('money-entries:source-type-gate')->assertExitCode(1);
    }

    public function test_gate_succeeds_after_all_legacy_unknown_entries_reviewed(): void
    {
        $entry = $this->makeLegacyUnknownEntry();

        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $entry->id,
            '--to' => 'manual',
            '--approver' => '王小美',
            '--reason' => '人工核對確認為一般收支',
        ])->assertExitCode(0);

        $this->artisan('money-entries:source-type-gate')->assertExitCode(0);
    }

    public function test_gate_fails_for_unresolved_preexisting_vehicle_shortcut_candidate_without_any_legacy_unknown(): void
    {
        $entry = $this->makeVehicleShortcutEntry();
        $this->seedUnresolvedCandidate($entry, 'preexisting_protected_source_type_needs_review');

        $this->assertSame(0, MoneyEntry::query()->where('source_type', 'legacy_unknown')->count());

        $this->artisan('money-entries:source-type-gate')->assertExitCode(1);
    }

    public function test_gate_succeeds_after_preexisting_candidate_is_reviewed(): void
    {
        $entry = $this->makeVehicleShortcutEntry();
        $this->seedUnresolvedCandidate($entry, 'preexisting_protected_source_type_needs_review');

        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $entry->id,
            '--to' => 'vehicle_shortcut',
            '--approver' => '王小美',
            '--reason' => '人工核對確認快捷收支來源正確',
        ])->assertExitCode(0);

        $this->artisan('money-entries:source-type-gate')->assertExitCode(0);
    }

    public function test_confirming_candidate_already_at_target_source_type_writes_review_log_and_is_idempotent(): void
    {
        Storage::fake('local');
        $entry = $this->makeVehicleShortcutEntry();
        $this->seedUnresolvedCandidate($entry, 'preexisting_protected_source_type_needs_review');

        $params = [
            '--ids' => (string) $entry->id,
            '--to' => 'vehicle_shortcut',
            '--approver' => '王小美',
            '--reason' => '人工核對確認快捷收支來源正確',
        ];

        $this->artisan('money-entries:source-type-review', $params)->assertExitCode(0);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'source_type' => 'vehicle_shortcut']);

        $review = DB::table('money_entry_source_type_reviews')->where('money_entry_id', $entry->id)->first();
        $this->assertNotNull($review);
        $this->assertSame('vehicle_shortcut', $review->previous_source_type);
        $this->assertSame('vehicle_shortcut', $review->new_source_type);
        $this->assertNull($review->backup_path);

        $candidate = DB::table('money_entry_source_type_review_candidates')->where('money_entry_id', $entry->id)->first();
        $this->assertNotNull($candidate->resolved_at);
        $this->assertSame('王小美', $candidate->resolved_by);

        Storage::disk('local')->assertDirectoryEmpty('money-entry-source-type-backups');

        $this->artisan('money-entries:source-type-review', $params)->assertExitCode(0);

        $this->assertSame(1, DB::table('money_entry_source_type_reviews')->where('money_entry_id', $entry->id)->count());
        Storage::disk('local')->assertDirectoryEmpty('money-entry-source-type-backups');
    }

    public function test_new_vehicle_shortcut_entry_without_candidate_snapshot_does_not_block_gate(): void
    {
        $this->makeVehicleShortcutEntry();

        $this->artisan('money-entries:source-type-gate')->assertExitCode(0);
    }

    public function test_backup_write_failure_aborts_without_mutating_data_or_writing_review_log(): void
    {
        $entry = $this->makeLegacyUnknownEntry();

        $diskMock = Mockery::mock();
        $diskMock->shouldReceive('put')->once()->andReturn(false);
        $diskMock->shouldNotReceive('exists');
        $diskMock->shouldNotReceive('get');

        Storage::shouldReceive('disk')->with('local')->andReturn($diskMock);

        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $entry->id,
            '--to' => 'manual',
            '--approver' => '王小美',
            '--reason' => '人工核對確認為一般收支',
        ])->assertExitCode(1);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'source_type' => 'legacy_unknown']);
        $this->assertSame(0, DB::table('money_entry_source_type_reviews')->count());
    }

    public function test_rollback_of_quarantine_migration_does_not_delete_review_evidence(): void
    {
        $entry = $this->makeLegacyUnknownEntry();

        DB::table('money_entry_source_type_reviews')->insert([
            'money_entry_id' => $entry->id,
            'previous_source_type' => 'legacy_unknown',
            'new_source_type' => 'manual',
            'approver' => '王小美',
            'reason' => '既有人工確認證據',
            'backup_path' => null,
            'money_entry_snapshot' => json_encode($entry->toArray()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_05_000002_quarantine_legacy_unknown_money_entry_source_type.php');
        $migration->down();

        $this->assertTrue(Schema::hasTable('money_entry_source_type_reviews'));
        $this->assertDatabaseHas('money_entry_source_type_reviews', ['money_entry_id' => $entry->id]);
    }

    public function test_rollback_of_candidate_migration_does_not_delete_candidate_evidence(): void
    {
        $entry = $this->makeVehicleShortcutEntry();
        $this->seedUnresolvedCandidate($entry, 'preexisting_protected_source_type_needs_review');

        $migration = require database_path('migrations/2026_07_05_000003_capture_money_entry_source_type_review_candidates.php');
        $migration->down();

        $this->assertTrue(Schema::hasTable('money_entry_source_type_review_candidates'));
        $this->assertDatabaseHas('money_entry_source_type_review_candidates', ['money_entry_id' => $entry->id]);
    }
}
