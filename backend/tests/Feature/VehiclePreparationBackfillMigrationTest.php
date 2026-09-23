<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 驗證 2026_07_06_000006_backfill_preparation_completed_for_active_vehicles：
 * is_preparation_completed 是在 000005 才新增的欄位，既有 listed/reserved/sold
 * 車輛預設為 false，但這些車輛能走到「上架中」以後代表當時已完成整備，
 * 回填 migration 需補上，且對 preparing 車輛與已經是 true 的資料無害、可重跑。
 */
class VehiclePreparationBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const BACKFILL_MIGRATION_PATH = 'migrations/2026_07_06_000006_backfill_preparation_completed_for_active_vehicles.php';

    private function requireBackfillMigration()
    {
        return require database_path(self::BACKFILL_MIGRATION_PATH);
    }

    public function test_backfill_marks_listed_reserved_and_sold_vehicles_as_preparation_completed(): void
    {
        $listed = Vehicle::factory()->create(['status' => 'listed', 'is_preparation_completed' => false]);
        $reserved = Vehicle::factory()->create(['status' => 'reserved', 'is_preparation_completed' => false]);
        $sold = Vehicle::factory()->create(['status' => 'sold', 'is_preparation_completed' => false]);

        $this->requireBackfillMigration()->up();

        foreach ([$listed, $reserved, $sold] as $vehicle) {
            $this->assertTrue(
                (bool) DB::table('vehicles')->where('id', $vehicle->id)->value('is_preparation_completed'),
                "vehicle {$vehicle->id} (status={$vehicle->status}) 應被回填為已完成整備"
            );
        }
    }

    public function test_backfill_does_not_touch_preparing_vehicles(): void
    {
        $preparing = Vehicle::factory()->create(['status' => 'preparing', 'is_preparation_completed' => false]);

        $this->requireBackfillMigration()->up();

        $this->assertFalse(
            (bool) DB::table('vehicles')->where('id', $preparing->id)->value('is_preparation_completed')
        );
    }

    public function test_backfill_is_safe_to_rerun(): void
    {
        $sold = Vehicle::factory()->create(['status' => 'sold', 'is_preparation_completed' => false]);

        $this->requireBackfillMigration()->up();
        $this->requireBackfillMigration()->up();

        $this->assertTrue(
            (bool) DB::table('vehicles')->where('id', $sold->id)->value('is_preparation_completed')
        );
    }
}
