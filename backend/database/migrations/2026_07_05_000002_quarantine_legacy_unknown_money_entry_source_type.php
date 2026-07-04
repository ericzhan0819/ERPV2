<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 這支 migration 做兩件事：
     *
     * 1. 建立 money_entry_source_type_reviews：僅用於本次資料修復的安全記錄表，
     *    記錄後續 money-entries:source-type-review 指令每一次人工確認變更，
     *    不是產品審計模組，不得延伸成正式 audit log。
     *
     * 2. 保守 quarantine：既有資料在 source_type 欄位新增前，沒有任何 durable
     *    provenance marker，無法證明「source_type = manual 且綁定車輛」的既有
     *    收支到底是一般 CRUD 建立、還是曾經跑過舊版不安全 backfill heuristic
     *    誤判。因此把這批 rows 統一改成 legacy_unknown（保護狀態，禁止一般
     *    CRUD 修改/刪除），交由人工用 money-entries:source-type-review 逐筆
     *    確認後才能改回 manual / vehicle_shortcut / vehicle_workflow。
     *    vehicle_id IS NULL 的一般營運 manual 收支不受影響，仍維持 manual。
     *    既有 vehicle_shortcut / vehicle_workflow 不改。
     */
    public function up(): void
    {
        if (! Schema::hasTable('money_entry_source_type_reviews')) {
            Schema::create('money_entry_source_type_reviews', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('money_entry_id')->index();
                $table->string('previous_source_type', 30);
                $table->string('new_source_type', 30);
                $table->string('approver');
                $table->text('reason');
                $table->string('backup_path')->nullable();
                $table->json('money_entry_snapshot')->nullable();
                $table->timestamps();
            });
        }

        DB::table('money_entries')
            ->where('source_type', 'manual')
            ->whereNotNull('vehicle_id')
            ->update(['source_type' => 'legacy_unknown']);
    }

    /**
     * 有意保持保守：不批次把 legacy_unknown 改回 manual，避免又用另一種
     * heuristic 誤傷資料。若需要 rollback，資料復原請透過
     * money-entries:source-type-review 人工確認每一筆的正確來源。
     *
     * down() 刻意保持 no-op、不 drop money_entry_source_type_reviews：這張表
     * 記錄的是人工 approver/reason/前後狀態/資料快照，一旦 rollback 就會刪掉
     * 已完成的人工確認證據，之後重新 up() 也救不回來。schema rollback 不應該
     * 連帶銷毀已經產生的人工審核紀錄。
     */
    public function down(): void
    {
        // 有意保持不刪除 money_entry_source_type_reviews，避免刪掉已完成的
        // 人工審核證據。
    }
};
