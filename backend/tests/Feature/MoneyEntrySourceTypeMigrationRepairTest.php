<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 驗證 2026_07_05_000004_repair_money_entry_source_type_migration_state：
 * 模擬「已經套用過舊版（沒有 durable state 邏輯）000002 / 000003，migrations
 * ledger 不會重跑這兩支已套用的 migration」的升級情境，只執行新的 000004
 * repair migration 完成升級，不依賴重跑 000002 / 000003。
 */
class MoneyEntrySourceTypeMigrationRepairTest extends TestCase
{
    use RefreshDatabase;

    private const REPAIR_MIGRATION_PATH = 'migrations/2026_07_05_000004_repair_money_entry_source_type_migration_state.php';

    private function requireRepairMigration()
    {
        return require database_path(self::REPAIR_MIGRATION_PATH);
    }

    /**
     * 模擬「舊版 000002 / 000003 已經套用過」的既有環境：durable state 表
     * 尚未存在，但 cohort / candidate 相關資料表與資料已經是舊版跑完的結果。
     */
    private function simulateLegacyAppliedState(): void
    {
        Schema::dropIfExists('money_entry_source_type_quarantine_state');
        Schema::dropIfExists('money_entry_source_type_review_candidate_capture_state');
    }

    private function insertLegacyUnknownEntry(): int
    {
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        return DB::table('money_entries')->insertGetId([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'entry_date' => '2026-06-01',
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 1000,
            'idempotency_key' => (string) Str::uuid(),
            'source_type' => 'legacy_unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_repair_migration_creates_missing_durable_state_tables_for_legacy_applied_environment(): void
    {
        $this->simulateLegacyAppliedState();

        $this->assertFalse(Schema::hasTable('money_entry_source_type_quarantine_state'));
        $this->assertFalse(Schema::hasTable('money_entry_source_type_review_candidate_capture_state'));

        $this->requireRepairMigration()->up();

        $this->assertTrue(Schema::hasTable('money_entry_source_type_quarantine_state'));
        $this->assertTrue(Schema::hasTable('money_entry_source_type_review_candidate_capture_state'));

        $quarantineState = DB::table('money_entry_source_type_quarantine_state')->first();
        $this->assertNotNull($quarantineState);
        $this->assertNotNull($quarantineState->cohort_completed_at);
        $this->assertNotNull($quarantineState->quarantine_completed_at);

        $captureState = DB::table('money_entry_source_type_review_candidate_capture_state')->first();
        $this->assertNotNull($captureState);
        $this->assertNotNull($captureState->completed_at);
    }

    public function test_repair_migration_adds_existing_legacy_unknown_rows_to_cohort_but_not_current_legitimate_manual_entries(): void
    {
        $this->simulateLegacyAppliedState();

        $legacyUnknownId = $this->insertLegacyUnknownEntry();

        // migration 後才合法新增的 manual 收支，不應該被納入 cohort。
        $legitimateEntry = MoneyEntry::factory()->create([
            'vehicle_id' => Vehicle::factory()->create(['status' => 'preparing'])->id,
            'cash_account_id' => CashAccount::factory()->create(['is_active' => true])->id,
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 500,
            'source_type' => 'manual',
        ]);

        $this->requireRepairMigration()->up();

        $this->assertDatabaseHas('money_entry_source_type_quarantine_cohort', ['money_entry_id' => $legacyUnknownId]);
        $this->assertDatabaseMissing('money_entry_source_type_quarantine_cohort', ['money_entry_id' => $legitimateEntry->id]);

        $this->assertSame(
            'manual',
            DB::table('money_entries')->where('id', $legitimateEntry->id)->value('source_type'),
            'migration 後才合法新增的 vehicle-bound manual 收支不得被誤 quarantine'
        );
    }

    public function test_repair_migration_preserves_existing_cohort_rows_and_supplements_remaining_legacy_unknown_rows(): void
    {
        $this->simulateLegacyAppliedState();

        $firstLegacyId = $this->insertLegacyUnknownEntry();
        $secondLegacyId = $this->insertLegacyUnknownEntry();

        // 模擬上一輪錯誤/部分 patch 已經建立 cohort table 且只補了其中一筆。
        DB::table('money_entry_source_type_quarantine_cohort')->insert([
            'money_entry_id' => $firstLegacyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->requireRepairMigration()->up();

        $this->assertDatabaseHas('money_entry_source_type_quarantine_cohort', ['money_entry_id' => $firstLegacyId]);
        $this->assertDatabaseHas('money_entry_source_type_quarantine_cohort', ['money_entry_id' => $secondLegacyId]);
    }

    public function test_repair_migration_builds_candidate_capture_state_from_existing_candidate_rows_without_expanding_to_new_entries(): void
    {
        Storage::fake('local');
        $this->simulateLegacyAppliedState();

        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        $preexistingCandidateEntry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '購車付款',
            'amount' => 2000,
            'source_type' => 'vehicle_shortcut',
        ]);

        DB::table('money_entry_source_type_review_candidates')->insert([
            'money_entry_id' => $preexistingCandidateEntry->id,
            'captured_source_type' => 'vehicle_shortcut',
            'candidate_reason' => 'preexisting_protected_source_type_needs_review',
            'money_entry_snapshot' => json_encode($preexistingCandidateEntry->toArray()),
            'resolved_at' => null,
            'resolved_by' => null,
            'resolution_review_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // migration 後才合法新增的 vehicle_shortcut 收支，不應該被補進 candidate。
        $newLegitEntry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '購車付款',
            'amount' => 3000,
            'source_type' => 'vehicle_shortcut',
        ]);

        $this->requireRepairMigration()->up();

        $captureState = DB::table('money_entry_source_type_review_candidate_capture_state')->first();
        $this->assertNotNull($captureState);
        $this->assertSame($preexistingCandidateEntry->id, (int) $captureState->cutoff_id);
        $this->assertNotNull($captureState->completed_at);

        $this->assertDatabaseMissing('money_entry_source_type_review_candidates', ['money_entry_id' => $newLegitEntry->id]);

        // gate 只會因為既有 unresolved candidate 卡住，不會被新增的合法收支擋住。
        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $preexistingCandidateEntry->id,
            '--to' => 'vehicle_shortcut',
            '--approver' => '王小美',
            '--reason' => '人工核對確認快捷收支來源正確',
        ])->assertExitCode(0);

        $this->artisan('money-entries:source-type-gate')->assertExitCode(0);
    }

    public function test_repair_migration_does_not_capture_any_candidate_when_candidate_table_has_no_evidence(): void
    {
        $this->simulateLegacyAppliedState();

        // 模擬曾經套用過 000002 但部署中斷在 000003 之前：candidate 表完全不存在。
        Schema::dropIfExists('money_entry_source_type_review_candidates');

        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        $protectedEntry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '購車付款',
            'amount' => 2000,
            'source_type' => 'vehicle_shortcut',
        ]);

        $this->requireRepairMigration()->up();

        $this->assertTrue(Schema::hasTable('money_entry_source_type_review_candidates'));

        $captureState = DB::table('money_entry_source_type_review_candidate_capture_state')->first();
        $this->assertNotNull($captureState);
        $this->assertSame(0, (int) $captureState->cutoff_id);
        $this->assertNotNull($captureState->completed_at);

        $this->assertDatabaseMissing('money_entry_source_type_review_candidates', ['money_entry_id' => $protectedEntry->id]);
    }

    public function test_repair_migration_does_not_fail_when_candidate_table_missing_and_no_legacy_unknown_rows_exist(): void
    {
        $this->simulateLegacyAppliedState();
        Schema::dropIfExists('money_entry_source_type_review_candidates');

        $this->requireRepairMigration()->up();

        $this->assertTrue(Schema::hasTable('money_entry_source_type_quarantine_state'));
        $this->assertTrue(Schema::hasTable('money_entry_source_type_review_candidate_capture_state'));
    }

    public function test_repair_migration_is_noop_when_state_already_exists(): void
    {
        // RefreshDatabase 已經完整跑過一次 migration，durable state 早已存在
        // （fresh install 路徑）。這裡不模擬 legacy applied state，驗證 000004
        // 對已經有 state 的環境完全不動作。
        $quarantineStateBefore = DB::table('money_entry_source_type_quarantine_state')->first();
        $captureStateBefore = DB::table('money_entry_source_type_review_candidate_capture_state')->first();

        $this->requireRepairMigration()->up();

        $quarantineStateAfter = DB::table('money_entry_source_type_quarantine_state')->first();
        $captureStateAfter = DB::table('money_entry_source_type_review_candidate_capture_state')->first();

        $this->assertEquals($quarantineStateBefore, $quarantineStateAfter);
        $this->assertEquals($captureStateBefore, $captureStateAfter);
    }

    public function test_reviewed_entry_is_not_requarantined_after_repair_and_rerun_of_quarantine_migration(): void
    {
        Storage::fake('local');
        $this->simulateLegacyAppliedState();

        $legacyId = $this->insertLegacyUnknownEntry();

        $this->requireRepairMigration()->up();

        $this->artisan('money-entries:source-type-review', [
            '--ids' => (string) $legacyId,
            '--to' => 'manual',
            '--approver' => '王小美',
            '--reason' => '人工核對確認為一般收支',
        ])->assertExitCode(0);

        $this->assertSame('manual', DB::table('money_entries')->where('id', $legacyId)->value('source_type'));

        // 模擬 rollback（down() no-op，但 ledger 移除）後重新 migrate 000002：
        // 只應該處理 000004 建立的 cohort，並排除已 review 的 id。
        $quarantineMigrationPath = database_path('migrations/2026_07_05_000002_quarantine_legacy_unknown_money_entry_source_type.php');
        (require $quarantineMigrationPath)->up();

        $this->assertSame(
            'manual',
            DB::table('money_entries')->where('id', $legacyId)->value('source_type'),
            '已人工 review 回 manual 的資料在 000004 repair 之後重跑 000002 up() 不得被重新 quarantine'
        );
    }

    public function test_rerunning_candidate_migration_after_repair_does_not_expand_snapshot_to_new_legitimate_entries(): void
    {
        $this->simulateLegacyAppliedState();

        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);

        $preexistingCandidateEntry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '購車付款',
            'amount' => 2000,
            'source_type' => 'vehicle_shortcut',
        ]);

        DB::table('money_entry_source_type_review_candidates')->insert([
            'money_entry_id' => $preexistingCandidateEntry->id,
            'captured_source_type' => 'vehicle_shortcut',
            'candidate_reason' => 'preexisting_protected_source_type_needs_review',
            'money_entry_snapshot' => json_encode($preexistingCandidateEntry->toArray()),
            'resolved_at' => null,
            'resolved_by' => null,
            'resolution_review_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->requireRepairMigration()->up();

        // 模擬 rollback（down() no-op，ledger 移除）後重新 migrate 000003。
        $candidateMigrationPath = database_path('migrations/2026_07_05_000003_capture_money_entry_source_type_review_candidates.php');

        $newLegitEntry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'cash_account_id' => $cashAccount->id,
            'direction' => 'expense',
            'category' => '購車付款',
            'amount' => 3000,
            'source_type' => 'vehicle_shortcut',
        ]);

        (require $candidateMigrationPath)->up();

        $this->assertDatabaseMissing('money_entry_source_type_review_candidates', ['money_entry_id' => $newLegitEntry->id]);
    }
}
