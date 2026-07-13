<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_period_id')->constrained('salary_periods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('eligible_sales_count')->default(0);
            $table->unsignedInteger('sales_bonus_bps_snapshot')->default(0);
            $table->unsignedBigInteger('base_salary_snapshot')->default(0);
            $table->unsignedBigInteger('fixed_allowance_snapshot')->default(0);
            $table->unsignedBigInteger('labor_insurance_deduction_snapshot')->default(0);
            $table->unsignedBigInteger('health_insurance_deduction_snapshot')->default(0);
            $table->unsignedBigInteger('purchase_bonus_total')->default(0);
            $table->unsignedBigInteger('sales_bonus_total')->default(0);
            $table->unsignedBigInteger('manual_addition_total')->default(0);
            $table->unsignedBigInteger('manual_deduction_total')->default(0);
            $table->unsignedBigInteger('gross_pay')->default(0);
            $table->unsignedBigInteger('deduction_total')->default(0);
            $table->unsignedBigInteger('net_pay')->default(0);
            $table->foreignId('money_entry_id')->nullable()->unique()->constrained('money_entries')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['salary_period_id', 'user_id'], 'salary_settlements_period_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_settlements');
    }
};
