<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Forward migration rather than editing already-applied 000002/000003: v1.3 phase 1
// was migrated to the development MariaDB while being verified. Keeping this repair
// separate makes fresh installs and already-migrated environments converge safely.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('vehicles', function (Blueprint $table) {
                // MySQL creates supporting FK indexes automatically, but SQLite does not.
                // Explicit names make the indexing contract portable and rollbackable.
                $table->index('purchase_agent_id', 'vehicles_purchase_agent_index');
                $table->index('sales_agent_id', 'vehicles_sales_agent_index');
            });

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER salary_periods_month_first_day_insert
                BEFORE INSERT ON salary_periods
                WHEN strftime('%d', NEW.period_month) <> '01'
                BEGIN
                    SELECT RAISE(ABORT, 'salary period month must be the first day');
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER salary_periods_month_first_day_update
                BEFORE UPDATE OF period_month ON salary_periods
                WHEN strftime('%d', NEW.period_month) <> '01'
                BEGIN
                    SELECT RAISE(ABORT, 'salary period month must be the first day');
                END
            SQL);
        } else {
            DB::statement('ALTER TABLE salary_periods ADD CONSTRAINT chk_salary_periods_month_first_day CHECK (DAY(period_month) = 1)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS salary_periods_month_first_day_update');
            DB::unprepared('DROP TRIGGER IF EXISTS salary_periods_month_first_day_insert');
        } else {
            DB::statement('ALTER TABLE salary_periods DROP CONSTRAINT chk_salary_periods_month_first_day');
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropIndex('vehicles_purchase_agent_index');
                $table->dropIndex('vehicles_sales_agent_index');
            });
        }
    }
};
