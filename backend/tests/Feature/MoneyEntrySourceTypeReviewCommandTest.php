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

    /**
     * 模擬 rollback（down() no-op，但 migrations ledger 移除）後重新 migrate
     * 會重新呼叫 000002 up()。人工已經透過 money-entries:source-type-review
     * 把某筆 vehicle-bound manual 收支從 legacy_unknown 確認回 manual，
     * 重跑 up() 不得把它重新 quarantine。
     */
    public function test_rerunning_quarantine_migration_up_does_not_requarantine_already_reviewed_entry(): void
    {
        Storage::fake('local');
        $migrationPath = database_path('migrations/2026_07_05_000002_quarantine_legacy_unknown_money_entry_source_type.php');

        // RefreshDatabase 已經在 money_entries 為空時跑過一次完整 migration，
        // 這裡移除 cohort 表，模擬這支 migration 第一次真正遇到既有 legacy
        // 資料的正式部署情境。
        Schema::dropIfExists('money_entry_source_type_quarantine_cohort');

        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        $entryId = DB::table('money_entries')->insertGetId([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'entry_date' => '2026-06-01',
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 1000,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
            // source_type omitted -> default manual
        ]);

        (require $migrationPath)->up();

        $this->assertSame('legacy_unknown', DB::table('money_entries')->where('id', $entryId)->value('source_type'));

        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $entryId,
            '--to' => 'manual',
            '--approver' => '王小美',
            '--reason' => '人工核對確認為一般收支',
        ])->assertExitCode(0);

        $this->assertSame('manual', DB::table('money_entries')->where('id', $entryId)->value('source_type'));

        // 模擬 rollback 後重新 migrate：再次呼叫 up()。
        (require $migrationPath)->up();

        $this->assertSame(
            'manual',
            DB::table('money_entries')->where('id', $entryId)->value('source_type'),
            '已人工 review 回 manual 的 vehicle-bound entry 重跑 up() 不得被重新 quarantine'
        );
    }

    /**
     * 重跑 000002 up() 不得誤傷 migration 第一次執行「之後」才新建立的合法
     * vehicle-bound manual 收支，因為它不屬於原本的 cohort 快照。
     */
    public function test_rerunning_quarantine_migration_up_does_not_affect_newly_created_manual_entry(): void
    {
        $migrationPath = database_path('migrations/2026_07_05_000002_quarantine_legacy_unknown_money_entry_source_type.php');

        // 模擬這支 migration 第一次真正遇到既有 legacy 資料的正式部署情境。
        Schema::dropIfExists('money_entry_source_type_quarantine_cohort');

        (require $migrationPath)->up();

        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        $newEntry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 500,
            'source_type' => 'manual',
        ]);

        // 模擬 rollback 後重新 migrate。
        (require $migrationPath)->up();

        $this->assertSame(
            'manual',
            DB::table('money_entries')->where('id', $newEntry->id)->value('source_type'),
            'migration 第一次執行之後才建立的合法 vehicle-bound manual 收支不得被重新 quarantine'
        );
    }

    /**
     * 已 resolved 的 candidate 再次被 reclassify 時，不得覆蓋原本的
     * resolved_at/resolved_by/resolution_review_id，但仍要新增一筆
     * review log。
     */
    public function test_reclassifying_already_resolved_candidate_preserves_original_resolution_evidence(): void
    {
        Storage::fake('local');
        $entry = $this->makeVehicleShortcutEntry();
        $this->seedUnresolvedCandidate($entry, 'preexisting_protected_source_type_needs_review');

        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $entry->id,
            '--to' => 'vehicle_shortcut',
            '--approver' => '王小美',
            '--reason' => '人工核對確認快捷收支來源正確',
        ])->assertExitCode(0);

        $candidateAfterFirstResolve = DB::table('money_entry_source_type_review_candidates')
            ->where('money_entry_id', $entry->id)
            ->first();

        $this->assertNotNull($candidateAfterFirstResolve->resolved_at);
        $this->assertSame('王小美', $candidateAfterFirstResolve->resolved_by);
        $firstResolutionReviewId = $candidateAfterFirstResolve->resolution_review_id;

        // 之後又有第二次人工 reclassify（例如改判斷為一般 manual 收支）。
        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $entry->id,
            '--to' => 'manual',
            '--approver' => '李大華',
            '--reason' => '重新核對後改判斷為一般收支',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('money_entries', ['id' => $entry->id, 'source_type' => 'manual']);

        $this->assertSame(
            2,
            DB::table('money_entry_source_type_reviews')->where('money_entry_id', $entry->id)->count(),
            '第二次 reclassify 必須新增一筆 review log'
        );

        $candidateAfterSecondReclassify = DB::table('money_entry_source_type_review_candidates')
            ->where('money_entry_id', $entry->id)
            ->first();

        $this->assertEquals(
            $candidateAfterFirstResolve->resolved_at,
            $candidateAfterSecondReclassify->resolved_at,
            '不得覆蓋原本第一次 resolve 的 resolved_at'
        );
        $this->assertSame(
            '王小美',
            $candidateAfterSecondReclassify->resolved_by,
            '不得覆蓋原本第一次 resolve 的 resolved_by'
        );
        $this->assertEquals(
            $firstResolutionReviewId,
            $candidateAfterSecondReclassify->resolution_review_id,
            '不得覆蓋原本第一次 resolve 的 resolution_review_id'
        );
    }

    /**
     * 000003 candidate capture 必須有固定 cutoff：migration 執行「當下」已
     * 存在的 vehicle_shortcut entry 要被捕捉，但 migration up() 呼叫「之後」
     * 才新增的合法 vehicle_shortcut / vehicle_workflow 收支，id 必然大於
     * cutoff，不會被補進 candidate 表，也不會被 gate 擋住。
     */
    public function test_candidate_capture_migration_does_not_capture_entries_created_after_cutoff(): void
    {
        $migrationPath = database_path('migrations/2026_07_05_000003_capture_money_entry_source_type_review_candidates.php');
        $migration = require $migrationPath;

        $preexistingEntry = $this->makeVehicleShortcutEntry();

        $migration->up();

        // migration 執行「之後」才新增的合法收支，id 大於 up() 呼叫當下固定
        // 的 cutoff，不應該被補進 candidate 快照。
        $entryAfterCutoff = $this->makeVehicleShortcutEntry();

        $this->assertDatabaseHas('money_entry_source_type_review_candidates', [
            'money_entry_id' => $preexistingEntry->id,
        ]);

        $this->assertDatabaseMissing('money_entry_source_type_review_candidates', [
            'money_entry_id' => $entryAfterCutoff->id,
        ]);

        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $preexistingEntry->id,
            '--to' => 'vehicle_shortcut',
            '--approver' => '王小美',
            '--reason' => '人工核對確認快捷收支來源正確',
        ])->assertExitCode(0);

        // gate 只會因為原本 cutoff 內的 preexistingEntry 卡住，一旦 review
        // 完成即可通過；cutoff 之後新增的 entryAfterCutoff 不應該造成 gate
        // 失敗。
        $this->artisan('money-entries:source-type-gate')->assertExitCode(0);
    }
}
