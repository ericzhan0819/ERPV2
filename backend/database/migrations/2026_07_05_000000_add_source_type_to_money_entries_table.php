<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_entries', function (Blueprint $table) {
            // 純粹的來源標記，用來保護 1.0 收支資料，不是會計來源/傳票欄位。
            // manual：一般收支 CRUD 建立，可在車輛未售出前修改/刪除。
            // vehicle_shortcut：購車付款/單車支出/收訂金/退款快捷建立，不可修改/刪除。
            // vehicle_workflow：reserve/final-payment 流程建立，不可修改/刪除。
            $table->string('source_type', 30)->default('manual')->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('money_entries', function (Blueprint $table) {
            $table->dropColumn('source_type');
        });
    }
};
