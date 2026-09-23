<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 2026_07_06_000005 新增 is_preparation_completed 時，既有 listed/reserved/sold
     * 車輛一律預設 false。但這些車輛能走到「上架中」以後的狀態，代表當時已完成整備
     * （即使該欄位尚不存在），因此回填為 true，避免既有資料顯示「整備未完成」與
     * 目前 listVehicle() 的不變量矛盾。可安全重跑：只更新仍為 false 的資料列。
     */
    public function up(): void
    {
        DB::table('vehicles')
            ->whereIn('status', ['listed', 'reserved', 'sold'])
            ->where('is_preparation_completed', false)
            ->update(['is_preparation_completed' => true]);
    }

    /**
     * 資料回填無法可靠復原（無法區分回填前的原始值），forward-only。
     */
    public function down(): void
    {
        // no-op：資料回填不提供復原
    }
};
