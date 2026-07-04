<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * 這支 migration 原本嘗試用 category / 車輛狀態 / buyer_name / sold_price
     * 等 heuristics，把既有 money_entries 的 source_type 從 manual 回填成
     * vehicle_shortcut / vehicle_workflow。
     *
     * 該做法不安全：既有資料在 source_type 欄位新增前，沒有任何 durable
     * provenance marker（idempotency_key 沒有 endpoint prefix、category 與
     * vehicle 狀態都只是巧合特徵，不是來源證明），會把透過一般
     * `/api/money-entries` CRUD 建立、但剛好綁車且分類相同的合法 manual
     * 收支，永久誤判成 vehicle_shortcut / vehicle_workflow，導致之後這些
     * 合法資料被一般 CRUD 拒絕修改/刪除。
     *
     * 因此本 migration 改為保守 no-op：
     * - 舊資料若無法證明來源，一律維持欄位新增時的 default（manual）。
     * - 新資料已由 MoneyEntryService::createEntry()、recordVehicleShortcut()、
     *   VehicleService::reserveVehicle() / recordFinalPayment() 在建立當下
     *   直接寫入正確的 source_type，不需要事後回填。
     * - 若環境曾經跑過本檔案的不安全舊版本，恢復方式請見
     *   docs/money-entry-source-type-recovery.md，不得再用另一種批次
     *   heuristic 反向覆蓋。
     */
    public function up(): void
    {
        // 有意保持 no-op，理由如上。
    }

    public function down(): void
    {
        // up() 本身不做任何資料變更，down() 對應保持 no-op。
    }
};
