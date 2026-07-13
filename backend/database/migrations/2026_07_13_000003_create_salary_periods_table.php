<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_month')->unique();
            $table->foreignId('commission_plan_id')->constrained('commission_plans')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->date('payment_date')->nullable();
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER salary_periods_status_insert
                BEFORE INSERT ON salary_periods
                WHEN NEW.status NOT IN ('draft', 'confirmed', 'paid')
                BEGIN
                    SELECT RAISE(ABORT, 'invalid salary period status');
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER salary_periods_status_update
                BEFORE UPDATE OF status ON salary_periods
                WHEN NEW.status NOT IN ('draft', 'confirmed', 'paid')
                BEGIN
                    SELECT RAISE(ABORT, 'invalid salary period status');
                END
            SQL);
        } else {
            DB::statement("ALTER TABLE salary_periods ADD CONSTRAINT chk_salary_periods_status CHECK (status IN ('draft', 'confirmed', 'paid'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS salary_periods_status_update');
            DB::unprepared('DROP TRIGGER IF EXISTS salary_periods_status_insert');
        }

        Schema::dropIfExists('salary_periods');
    }
};
