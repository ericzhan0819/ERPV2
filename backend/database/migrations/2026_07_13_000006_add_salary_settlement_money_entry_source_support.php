<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // source_type 原本就是 VARCHAR(30)，SQLite 不需變更即可保存新值。正式
        // MySQL/MariaDB 額外建立白名單 CHECK，讓繞過應用層的未知來源寫入也失敗。
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE money_entries ADD CONSTRAINT chk_money_entries_source_type CHECK (source_type IN ('manual', 'vehicle_shortcut', 'vehicle_workflow', 'legacy_unknown', 'salary_settlement'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE money_entries DROP CONSTRAINT chk_money_entries_source_type');
        }
    }
};
