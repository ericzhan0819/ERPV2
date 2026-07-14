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
        // 這裡移除 cohort 表與 state 表，模擬這支 migration 第一次真正遇到
        // 既有 legacy 資料的正式部署情境。
        Schema::dropIfExists('money_entry_source_type_quarantine_cohort');
        Schema::dropIfExists('money_entry_source_type_quarantine_state');

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
            // 此段說明相鄰程式碼的用途與預期行為。
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
        Schema::dropIfExists('money_entry_source_type_quarantine_state');

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

        // RefreshDatabase 已經在 money_entries 為空時跑過一次完整 migration，
        // 固定了 cutoff=0 且標記完成。這裡移除 capture state 表，模擬這支
        // migration 第一次真正遇到既有資料的正式部署情境。
        Schema::dropIfExists('money_entry_source_type_review_candidate_capture_state');

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

    /**
     * 000003 candidate capture 必須用 durable cutoff：rollback（down() no-op，
     * 但 migrations ledger 移除）後重新 migrate 呼叫 up()，不得用「目前」
     * money_entries 最大 id 重新算出更大的 cutoff。若用目前最大 id 重算，
     * rollback 與重新 migrate 之間新增的合法 vehicle_shortcut 收支就會落在
     * 新 cutoff 內被誤補進 candidate、誤擋 gate。
     */
    public function test_rerunning_candidate_capture_migration_after_rollback_does_not_expand_snapshot_to_new_legitimate_entries(): void
    {
        $migrationPath = database_path('migrations/2026_07_05_000003_capture_money_entry_source_type_review_candidates.php');

        // 模擬這支 migration 第一次真正遇到既有資料的正式部署情境。
        Schema::dropIfExists('money_entry_source_type_review_candidate_capture_state');

        $preexistingEntry = $this->makeVehicleShortcutEntry();

        (require $migrationPath)->up();

        $this->assertDatabaseHas('money_entry_source_type_review_candidates', [
            'money_entry_id' => $preexistingEntry->id,
        ]);

        // 模擬 rollback：down() no-op，但 migrations ledger 移除。
        (require $migrationPath)->down();

        // rollback 與重新 migrate 之間，正常新增的合法 vehicle_shortcut 收支。
        $newLegitEntry = $this->makeVehicleShortcutEntry();

        // 模擬重新 migrate：再次呼叫 up()。
        (require $migrationPath)->up();

        $this->assertDatabaseMissing('money_entry_source_type_review_candidates', [
            'money_entry_id' => $newLegitEntry->id,
        ]);

        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $preexistingEntry->id,
            '--to' => 'vehicle_shortcut',
            '--approver' => '王小美',
            '--reason' => '人工核對確認快捷收支來源正確',
        ])->assertExitCode(0);

        // gate 不應該被 rollback 後、重新 migrate 前新增的合法收支擋住。
        $this->artisan('money-entries:source-type-gate')->assertExitCode(0);
    }

    /**
     * 模擬 000002 crash 情境一：cohort table 已建立且完整 populate，但
     * quarantine update 尚未套用完成（quarantine_completed_at 仍是 null）。
     * 下一次重跑 up() 必須完成剩餘的 quarantine 套用，不得誤判成已完成而
     * 跳過。
     */
    public function test_rerunning_quarantine_migration_completes_pending_quarantine_when_cohort_already_populated(): void
    {
        $migrationPath = database_path('migrations/2026_07_05_000002_quarantine_legacy_unknown_money_entry_source_type.php');

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
            // 此段說明相鄰程式碼的用途與預期行為。
        ]);

        $state = DB::table('money_entry_source_type_quarantine_state')->first();
        $this->assertNotNull($state);

        // 模擬 crash：cohort 已完整 populate（把新 entry 補進 cohort），但
        // quarantine update 尚未套用。
        DB::table('money_entry_source_type_quarantine_cohort')->insert([
            'money_entry_id' => $entryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('money_entry_source_type_quarantine_state')->where('id', $state->id)->update([
            'cutoff_id' => $entryId,
            'cohort_completed_at' => now(),
            'quarantine_completed_at' => null,
            'updated_at' => now(),
        ]);

        (require $migrationPath)->up();

        $this->assertSame('legacy_unknown', DB::table('money_entries')->where('id', $entryId)->value('source_type'));
        $this->assertNotNull(
            DB::table('money_entry_source_type_quarantine_state')->where('id', $state->id)->value('quarantine_completed_at')
        );
    }

    /**
     * 模擬 000002 crash 情境二：cohort table 已建立，但只 populate 一部分
     * （cohort_completed_at 仍是 null）。下一次重跑 up() 必須補齊剩餘符合
     * 條件的 legacy vehicle-bound manual 收支，不得因為 cohort 目前已有資料
     * 就誤判成完成而漏掉其餘的。
     */
    public function test_rerunning_quarantine_migration_backfills_remaining_rows_after_partial_cohort_population(): void
    {
        $migrationPath = database_path('migrations/2026_07_05_000002_quarantine_legacy_unknown_money_entry_source_type.php');

        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicleA = Vehicle::factory()->create(['status' => 'preparing']);
        $vehicleB = Vehicle::factory()->create(['status' => 'preparing']);

        $entryIdA = DB::table('money_entries')->insertGetId([
            'vehicle_id' => $vehicleA->id,
            'cash_account_id' => $cashAccount->id,
            'entry_date' => '2026-06-01',
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 1000,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entryIdB = DB::table('money_entries')->insertGetId([
            'vehicle_id' => $vehicleB->id,
            'cash_account_id' => $cashAccount->id,
            'entry_date' => '2026-06-01',
            'direction' => 'expense',
            'category' => '購車付款',
            'amount' => 2000,
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $state = DB::table('money_entry_source_type_quarantine_state')->first();
        $this->assertNotNull($state);

        // 模擬 partial cohort population：cutoff 涵蓋兩筆，但 cohort 只補進
        // 其中一筆，另一筆尚未寫入，且尚未標記 cohort_completed_at。
        DB::table('money_entry_source_type_quarantine_cohort')->insert([
            'money_entry_id' => $entryIdA,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('money_entry_source_type_quarantine_state')->where('id', $state->id)->update([
            'cutoff_id' => $entryIdB,
            'cohort_completed_at' => null,
            'quarantine_completed_at' => null,
            'updated_at' => now(),
        ]);

        (require $migrationPath)->up();

        $this->assertSame('legacy_unknown', DB::table('money_entries')->where('id', $entryIdA)->value('source_type'));
        $this->assertSame('legacy_unknown', DB::table('money_entries')->where('id', $entryIdB)->value('source_type'));
        $this->assertDatabaseHas('money_entry_source_type_quarantine_cohort', ['money_entry_id' => $entryIdB]);
    }

    /**
     * 模擬 000002 crash 情境三：000002 完整跑完一輪（cohort/quarantine 皆
     * completed），但 000003 建立的 candidate 表尚不存在（例如部署順序中
     * 000003 尚未執行到，或該環境曾經只跑到 000002 就中斷）。重新 migrate
     * 重跑 000002 up() 進入排除已 review id 的分支時，不得因為查詢不存在的
     * candidate 表而失敗。
     */
    public function test_rerunning_quarantine_migration_up_does_not_fail_when_candidate_table_missing(): void
    {
        $migrationPath = database_path('migrations/2026_07_05_000002_quarantine_legacy_unknown_money_entry_source_type.php');

        Schema::dropIfExists('money_entry_source_type_quarantine_cohort');
        Schema::dropIfExists('money_entry_source_type_quarantine_state');

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
        ]);

        (require $migrationPath)->up();

        $this->assertSame('legacy_unknown', DB::table('money_entries')->where('id', $entryId)->value('source_type'));

        // 模擬 000003 candidate 表尚不存在（例如部署中斷在 000002 之後、
        // 000003 之前）。
        Schema::dropIfExists('money_entry_source_type_review_candidates');

        // 模擬 rollback 後重新 migrate：再次呼叫 up()，此時應進入完整 rerun
        // 分支（quarantine_completed_at 已標記完成）。
        (require $migrationPath)->up();

        $this->assertSame('legacy_unknown', DB::table('money_entries')->where('id', $entryId)->value('source_type'));
    }
}
