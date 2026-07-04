<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
}
