<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER commission_plans_immutable_update
                BEFORE UPDATE OF effective_from, company_reserve_bps, purchase_bonus_bps ON commission_plans
                WHEN EXISTS (SELECT 1 FROM salary_periods WHERE commission_plan_id = OLD.id)
                BEGIN
                    SELECT RAISE(ABORT, 'referenced commission plan calculation fields are immutable');
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER commission_tiers_immutable_insert
                BEFORE INSERT ON commission_plan_tiers
                WHEN EXISTS (SELECT 1 FROM salary_periods WHERE commission_plan_id = NEW.commission_plan_id)
                BEGIN
                    SELECT RAISE(ABORT, 'referenced commission plan tiers are immutable');
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER commission_tiers_immutable_update
                BEFORE UPDATE ON commission_plan_tiers
                WHEN EXISTS (SELECT 1 FROM salary_periods WHERE commission_plan_id = OLD.commission_plan_id)
                  OR EXISTS (SELECT 1 FROM salary_periods WHERE commission_plan_id = NEW.commission_plan_id)
                BEGIN
                    SELECT RAISE(ABORT, 'referenced commission plan tiers are immutable');
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER commission_tiers_immutable_delete
                BEFORE DELETE ON commission_plan_tiers
                WHEN EXISTS (SELECT 1 FROM salary_periods WHERE commission_plan_id = OLD.commission_plan_id)
                BEGIN
                    SELECT RAISE(ABORT, 'referenced commission plan tiers are immutable');
                END
            SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER commission_plans_immutable_update
            BEFORE UPDATE ON commission_plans FOR EACH ROW
            BEGIN
                IF EXISTS (SELECT 1 FROM salary_periods WHERE commission_plan_id = OLD.id)
                   AND (NOT (NEW.effective_from <=> OLD.effective_from)
                        OR NEW.company_reserve_bps <> OLD.company_reserve_bps
                        OR NEW.purchase_bonus_bps <> OLD.purchase_bonus_bps) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'referenced commission plan calculation fields are immutable';
                END IF;
            END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER commission_tiers_immutable_insert
            BEFORE INSERT ON commission_plan_tiers FOR EACH ROW
            BEGIN
                IF EXISTS (SELECT 1 FROM salary_periods WHERE commission_plan_id = NEW.commission_plan_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'referenced commission plan tiers are immutable';
                END IF;
            END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER commission_tiers_immutable_update
            BEFORE UPDATE ON commission_plan_tiers FOR EACH ROW
            BEGIN
                IF EXISTS (SELECT 1 FROM salary_periods WHERE commission_plan_id IN (OLD.commission_plan_id, NEW.commission_plan_id)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'referenced commission plan tiers are immutable';
                END IF;
            END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER commission_tiers_immutable_delete
            BEFORE DELETE ON commission_plan_tiers FOR EACH ROW
            BEGIN
                IF EXISTS (SELECT 1 FROM salary_periods WHERE commission_plan_id = OLD.commission_plan_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'referenced commission plan tiers are immutable';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS commission_tiers_immutable_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS commission_tiers_immutable_update');
        DB::unprepared('DROP TRIGGER IF EXISTS commission_tiers_immutable_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS commission_plans_immutable_update');
    }
};
