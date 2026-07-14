<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL 的 trigger DDL 不受 migration transaction 保護。若前次部署只建立到
        // 一半就失敗，重跑前先清掉所有同名 trigger，避免卡在 already exists。
        foreach ($this->triggerNames() as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }

        $triggers = Schema::getConnection()->getDriverName() === 'sqlite'
            ? $this->sqliteTriggers()
            : $this->mysqlTriggers();

        foreach ($triggers as $sql) {
            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        foreach ($this->triggerNames() as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
    }

    /** @return string[] */
    private function triggerNames(): array
    {
        return [
            'paid_salary_period_update', 'paid_salary_period_delete',
            'paid_salary_settlement_insert', 'paid_salary_settlement_update', 'paid_salary_settlement_delete',
            'paid_salary_item_insert', 'paid_salary_item_update', 'paid_salary_item_delete',
        ];
    }

    /** @return string[] */
    private function sqliteTriggers(): array
    {
        return [
            $this->sqlitePeriodTrigger('paid_salary_period_update', 'UPDATE'),
            $this->sqlitePeriodTrigger('paid_salary_period_delete', 'DELETE'),
            $this->sqliteSettlementTrigger('paid_salary_settlement_insert', 'INSERT'),
            $this->sqliteSettlementTrigger('paid_salary_settlement_update', 'UPDATE'),
            $this->sqliteSettlementTrigger('paid_salary_settlement_delete', 'DELETE'),
            $this->sqliteItemTrigger('paid_salary_item_insert', 'INSERT'),
            $this->sqliteItemTrigger('paid_salary_item_update', 'UPDATE'),
            $this->sqliteItemTrigger('paid_salary_item_delete', 'DELETE'),
        ];
    }

    /** @return string[] */
    private function mysqlTriggers(): array
    {
        return [
            $this->mysqlPeriodTrigger('paid_salary_period_update', 'UPDATE'),
            $this->mysqlPeriodTrigger('paid_salary_period_delete', 'DELETE'),
            $this->mysqlSettlementTrigger('paid_salary_settlement_insert', 'INSERT'),
            $this->mysqlSettlementTrigger('paid_salary_settlement_update', 'UPDATE'),
            $this->mysqlSettlementTrigger('paid_salary_settlement_delete', 'DELETE'),
            $this->mysqlItemTrigger('paid_salary_item_insert', 'INSERT'),
            $this->mysqlItemTrigger('paid_salary_item_update', 'UPDATE'),
            $this->mysqlItemTrigger('paid_salary_item_delete', 'DELETE'),
        ];
    }

    private function sqlitePeriodTrigger(string $name, string $event): string
    {
        return "CREATE TRIGGER {$name} BEFORE {$event} ON salary_periods
            WHEN OLD.status = 'paid'
            BEGIN SELECT RAISE(ABORT, 'paid salary periods are immutable'); END";
    }

    private function sqliteSettlementTrigger(string $name, string $event): string
    {
        $references = $event === 'UPDATE'
            ? 'OLD.salary_period_id, NEW.salary_period_id'
            : ($event === 'INSERT' ? 'NEW.salary_period_id' : 'OLD.salary_period_id');

        return "CREATE TRIGGER {$name} BEFORE {$event} ON salary_settlements
            WHEN EXISTS (SELECT 1 FROM salary_periods WHERE id IN ({$references}) AND status = 'paid')
            BEGIN SELECT RAISE(ABORT, 'paid salary settlements are immutable'); END";
    }

    private function sqliteItemTrigger(string $name, string $event): string
    {
        $references = $event === 'UPDATE'
            ? 'OLD.salary_settlement_id, NEW.salary_settlement_id'
            : ($event === 'INSERT' ? 'NEW.salary_settlement_id' : 'OLD.salary_settlement_id');

        return "CREATE TRIGGER {$name} BEFORE {$event} ON salary_settlement_items
            WHEN EXISTS (
                SELECT 1 FROM salary_settlements s
                JOIN salary_periods p ON p.id = s.salary_period_id
                WHERE s.id IN ({$references}) AND p.status = 'paid'
            )
            BEGIN SELECT RAISE(ABORT, 'paid salary settlement items are immutable'); END";
    }

    private function mysqlPeriodTrigger(string $name, string $event): string
    {
        return "CREATE TRIGGER {$name} BEFORE {$event} ON salary_periods FOR EACH ROW
            BEGIN IF OLD.status = 'paid' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'paid salary periods are immutable';
            END IF; END";
    }

    private function mysqlSettlementTrigger(string $name, string $event): string
    {
        $references = $event === 'UPDATE'
            ? 'OLD.salary_period_id, NEW.salary_period_id'
            : ($event === 'INSERT' ? 'NEW.salary_period_id' : 'OLD.salary_period_id');

        return "CREATE TRIGGER {$name} BEFORE {$event} ON salary_settlements FOR EACH ROW
            BEGIN IF EXISTS (SELECT 1 FROM salary_periods WHERE id IN ({$references}) AND status = 'paid') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'paid salary settlements are immutable';
            END IF; END";
    }

    private function mysqlItemTrigger(string $name, string $event): string
    {
        $references = $event === 'UPDATE'
            ? 'OLD.salary_settlement_id, NEW.salary_settlement_id'
            : ($event === 'INSERT' ? 'NEW.salary_settlement_id' : 'OLD.salary_settlement_id');

        return "CREATE TRIGGER {$name} BEFORE {$event} ON salary_settlement_items FOR EACH ROW
            BEGIN IF EXISTS (
                SELECT 1 FROM salary_settlements s
                JOIN salary_periods p ON p.id = s.salary_period_id
                WHERE s.id IN ({$references}) AND p.status = 'paid'
            ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'paid salary settlement items are immutable';
            END IF; END";
    }
};
