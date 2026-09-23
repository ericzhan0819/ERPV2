<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared($this->sqliteTrigger('salary_money_entry_contract_insert', 'INSERT'));
            DB::unprepared($this->sqliteTrigger('salary_money_entry_contract_update', 'UPDATE'));

            return;
        }

        DB::unprepared($this->mysqlTrigger('salary_money_entry_contract_insert', 'INSERT'));
        DB::unprepared($this->mysqlTrigger('salary_money_entry_contract_update', 'UPDATE'));
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS salary_money_entry_contract_update');
        DB::unprepared('DROP TRIGGER IF EXISTS salary_money_entry_contract_insert');
    }

    private function sqliteTrigger(string $name, string $event): string
    {
        return <<<SQL
            CREATE TRIGGER {$name}
            BEFORE {$event} ON money_entries
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
        SQL;
    }

    private function mysqlTrigger(string $name, string $event): string
    {
        return <<<SQL
            CREATE TRIGGER {$name}
            BEFORE {$event} ON money_entries FOR EACH ROW
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
        SQL;
    }
};
