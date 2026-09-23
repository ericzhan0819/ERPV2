<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // 歷史資料刻意保持 NULL：created_by / updated_by 並非 durable provenance，
            // 不可用來猜測實際收車人或賣車人。
            $table->foreignId('purchase_agent_id')->nullable()->after('purchase_price')
                ->constrained('users')->restrictOnDelete();
            $table->foreignId('sales_agent_id')->nullable()->after('sold_price')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_agent_id');
            $table->dropConstrainedForeignId('sales_agent_id');
        });
    }
};
