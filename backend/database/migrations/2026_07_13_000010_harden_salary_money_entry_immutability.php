<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 此段說明相鄰程式碼的用途與預期行為。
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS salary_money_entry_contract_update');

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER salary_money_entry_contract_update
                BEFORE UPDATE ON money_entries
                WHEN OLD.source_type = 'salary_settlement'
                  OR (
                    NEW.source_type = 'salary_settlement'
                    AND (
                      NEW.approval_status <> 'approved'
                      OR NEW.direction <> 'expense'
                      OR NEW.category <> '薪資 / 佣金'
                      OR NEW.vehicle_id IS NOT NULL
                      OR NEW.created_by IS NULL
                      OR NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.created_by AND role = 'admin')
                    )
                  )
                BEGIN
                    SELECT RAISE(ABORT, 'salary settlement money entries are immutable');
                END
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER salary_money_entry_contract_delete
                BEFORE DELETE ON money_entries
                WHEN OLD.source_type = 'salary_settlement'
                BEGIN
                    SELECT RAISE(ABORT, 'salary settlement money entries are immutable');
                END
            SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER salary_money_entry_contract_update
            BEFORE UPDATE ON money_entries FOR EACH ROW
            BEGIN
                IF OLD.source_type = 'salary_settlement'
                   OR (
                     NEW.source_type = 'salary_settlement'
                     AND (
                       NEW.approval_status <> 'approved'
                       OR NEW.direction <> 'expense'
                       OR NEW.category <> '薪資 / 佣金'
                       OR NEW.vehicle_id IS NOT NULL
                       OR NEW.created_by IS NULL
                       OR NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.created_by AND role = 'admin')
                     )
                   ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'salary settlement money entries are immutable';
                END IF;
            END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER salary_money_entry_contract_delete
            BEFORE DELETE ON money_entries FOR EACH ROW
            BEGIN
                IF OLD.source_type = 'salary_settlement' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'salary settlement money entries are immutable';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS salary_money_entry_contract_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS salary_money_entry_contract_update');

        // 此段說明相鄰程式碼的用途與預期行為。
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER salary_money_entry_contract_update
                BEFORE UPDATE ON money_entries
                WHEN NEW.source_type = 'salary_settlement'
                  AND (
                    NEW.approval_status <> 'approved'
                    OR NEW.direction <> 'expense'
                    OR NEW.category <> '薪資 / 佣金'
                    OR NEW.vehicle_id IS NOT NULL
                    OR NEW.created_by IS NULL
                    OR NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.created_by AND role = 'admin')
                  )
                BEGIN
                    SELECT RAISE(ABORT, 'invalid salary settlement money entry contract');
                END
            SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER salary_money_entry_contract_update
            BEFORE UPDATE ON money_entries FOR EACH ROW
            BEGIN
                IF NEW.source_type = 'salary_settlement'
                   AND (
                     NEW.approval_status <> 'approved'
                     OR NEW.direction <> 'expense'
                     OR NEW.category <> '薪資 / 佣金'
                     OR NEW.vehicle_id IS NOT NULL
                     OR NEW.created_by IS NULL
                     OR NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.created_by AND role = 'admin')
                   ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid salary settlement money entry contract';
                END IF;
            END
        SQL);
    }
};
