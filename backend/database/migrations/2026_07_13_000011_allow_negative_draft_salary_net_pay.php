<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_settlements', function (Blueprint $table) {
            // 草稿必須能忠實呈現扣款高於應發的負數，確認時再由 Service 阻擋。
            $table->bigInteger('net_pay')->default(0)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('salary_settlements')->where('net_pay', '<', 0)->exists()) {
            throw new RuntimeException('仍有負數薪資草稿，無法安全回復 net_pay unsigned schema；請先修正或刪除草稿');
        }

        Schema::table('salary_settlements', function (Blueprint $table) {
            $table->unsignedBigInteger('net_pay')->default(0)->change();
        });
    }
};
