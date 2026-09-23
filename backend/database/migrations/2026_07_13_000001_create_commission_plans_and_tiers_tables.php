<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->date('effective_from')->index();
            $table->unsignedInteger('company_reserve_bps');
            $table->unsignedInteger('purchase_bonus_bps');
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('commission_plan_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_plan_id')->constrained('commission_plans')->cascadeOnDelete();
            $table->unsignedInteger('min_sales_count');
            $table->unsignedInteger('sales_bonus_bps');
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['commission_plan_id', 'min_sales_count'], 'commission_tiers_plan_min_unique');
            $table->unique(['commission_plan_id', 'sort_order'], 'commission_tiers_plan_sort_unique');
        });

        // SQLite 無法在建表後以 ALTER TABLE 補 named CHECK；測試與未來寫入 Service
        // 仍會驗證同一界線。正式 MySQL/MariaDB 則加 DB constraint，避免繞過應用層
        // 寫入超出 basis-points 範圍的資料。
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE commission_plans ADD CONSTRAINT chk_commission_plans_reserve_bps CHECK (company_reserve_bps BETWEEN 0 AND 10000)');
            DB::statement('ALTER TABLE commission_plans ADD CONSTRAINT chk_commission_plans_purchase_bps CHECK (purchase_bonus_bps BETWEEN 0 AND 10000)');
            DB::statement('ALTER TABLE commission_plan_tiers ADD CONSTRAINT chk_commission_tiers_min_sales_positive CHECK (min_sales_count >= 1)');
            DB::statement('ALTER TABLE commission_plan_tiers ADD CONSTRAINT chk_commission_tiers_sales_bps CHECK (sales_bonus_bps BETWEEN 0 AND 10000)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_plan_tiers');
        Schema::dropIfExists('commission_plans');
    }
};
