<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('salary_periods', 'company_reserve_total')
            && Schema::hasColumn('salary_periods', 'company_remaining_total')) {
            return;
        }

        // 舊版沒有保存「未建立員工 settlement 的合格車」公司分配快照，無法由現有
        // item 安全回填。若已有鎖定月份便在 DDL 前停止，避免把 0 寫成正式歷史數字。
        if (DB::table('salary_periods')->whereIn('status', ['confirmed', 'paid'])->exists()) {
            throw new RuntimeException(
                '偵測到既有已確認／已發薪月份，無法安全自動回填公司分配總額；請先人工評估歷史資料後再部署。',
            );
        }

        Schema::table('salary_periods', function (Blueprint $table) {
            $table->unsignedBigInteger('company_reserve_total')->nullable()->after('status');
            $table->unsignedBigInteger('company_remaining_total')->nullable()->after('company_reserve_total');
        });
    }

    public function down(): void
    {
        Schema::table('salary_periods', function (Blueprint $table) {
            $table->dropColumn(['company_reserve_total', 'company_remaining_total']);
        });
    }
};
