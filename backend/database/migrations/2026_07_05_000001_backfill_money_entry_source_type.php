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
     * 因此本 migration 保持 no-op，理由是：
     * - 本檔案可能已經在既有環境中被記錄為「已執行」（entry 存在於
     *   migrations 表），若之後改成在這裡寫資料，等於對舊環境變成
     *   no-op、對全新環境才生效，行為不一致且難以追蹤。
     * - 真正的既有資料保護（保守 quarantine 成 legacy_unknown，而非
     *   heuristic 回填）交給下一支 forward-only migration
     *   2026_07_05_000002_quarantine_legacy_unknown_money_entry_source_type.php
     *   處理，並提供 money-entries:source-type-review /
     *   money-entries:source-type-gate 兩個 Artisan 指令做人工確認與部署
     *   gate。
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
