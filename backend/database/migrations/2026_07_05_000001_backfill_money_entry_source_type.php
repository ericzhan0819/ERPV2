<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 資料保護回填：把 2026_07_05_000000 新增 source_type 欄位時，
     * 被 default 成 manual 的既有車輛快捷/流程收支，回填回正確的
     * vehicle_shortcut / vehicle_workflow，避免舊資料被一般收支 CRUD 修改/刪除。
     *
     * 只處理 vehicle_id IS NOT NULL 且目前仍是 manual 的既有資料，
     * 一般營運收支（含未綁車的其他支出、其他單車收入）一律保持 manual。
     */
    private const SHORTCUT_ONLY_CATEGORIES = [
        '購車付款',
        '維修支出',
        '美容支出',
        '代辦支出',
        '拍場支出',
        '其他支出',
        '退款',
    ];

    public function up(): void
    {
        DB::table('money_entries')
            ->whereNotNull('vehicle_id')
            ->where('source_type', 'manual')
            ->whereIn('category', self::SHORTCUT_ONLY_CATEGORIES)
            ->update(['source_type' => 'vehicle_shortcut']);

        DB::table('money_entries')
            ->whereNotNull('vehicle_id')
            ->where('source_type', 'manual')
            ->where('category', '尾款收入')
            ->update(['source_type' => 'vehicle_workflow']);

        // 訂金收入：僅當關聯車輛具備 reserve 流程造成的狀態特徵
        // （status in reserved/sold、已有 sold_price、buyer_name 與收支的
        // counterparty_name 相符）才視為 vehicle_workflow；其餘 vehicle-bound
        // 訂金收入視為 vehicle_shortcut（收訂金快捷建立）。
        // 用 select + whereIn 而非 UPDATE...JOIN，因為 SQLite（測試環境）不支援
        // UPDATE 搭配 JOIN 的語法。
        $workflowDepositEntryIds = DB::table('money_entries as me')
            ->join('vehicles as v', 'v.id', '=', 'me.vehicle_id')
            ->where('me.source_type', 'manual')
            ->where('me.category', '訂金收入')
            ->whereIn('v.status', ['reserved', 'sold'])
            ->whereNotNull('v.sold_price')
            ->whereColumn('v.buyer_name', 'me.counterparty_name')
            ->pluck('me.id');

        if ($workflowDepositEntryIds->isNotEmpty()) {
            DB::table('money_entries')
                ->whereIn('id', $workflowDepositEntryIds)
                ->update(['source_type' => 'vehicle_workflow']);
        }

        DB::table('money_entries')
            ->whereNotNull('vehicle_id')
            ->where('source_type', 'manual')
            ->where('category', '訂金收入')
            ->update(['source_type' => 'vehicle_shortcut']);

        // 其他單車收入：目前沒有 vehicle shortcut endpoint 會建立此分類，
        // 既有資料一律視為一般 CRUD 建立，維持 manual，不回填。
    }

    public function down(): void
    {
        // 這是資料保護回填，不安全反向推回 manual：若 rollback 時把
        // vehicle_shortcut / vehicle_workflow 改回 manual，會重新讓已受保護的
        // 歷史收支可被一般收支 CRUD 修改/刪除。down() 刻意 no-op。
    }
};
