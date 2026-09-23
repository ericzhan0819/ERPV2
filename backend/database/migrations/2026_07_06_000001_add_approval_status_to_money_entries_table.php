<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_entries', function (Blueprint $table) {
            // 只套用於 source_type=manual 的一般收支審核流程；vehicle_shortcut /
            // vehicle_workflow 建立時一律直接寫入 approved，不進審核佇列。
            // default('approved') 讓既有資料與遷移當下已存在的 row 一律回填為 approved，
            // 避免 v1.1 上線後追溯影響既有餘額。
            $table->string('approval_status', 20)->default('approved')->after('source_type');
            $table->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('money_entries', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'approved_at']);
        });
    }
};
