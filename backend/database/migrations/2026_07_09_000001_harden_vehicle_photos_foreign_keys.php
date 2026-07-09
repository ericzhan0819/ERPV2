<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Forward-only fix: environments that already ran 2026_07_09_000000 recorded it in
// migrations and will never re-run its (edited) body. This migration corrects the
// live schema instead: vehicle_id was CASCADE (a vehicle delete could silently wipe
// photo rows before storage cleanup) and uploaded_by was nullable + SET NULL (a user
// delete could erase photo upload attribution). Both are hardened to RESTRICT, matching
// the app-level guards added in VehicleService::deleteVehicle() and UserService::deleteUser().
//
// Whichever columns need fixing (drop the old FK, adjust nullability if needed,
// add the new FK) are all issued together as ONE combined ALTER TABLE statement
// per up()/down() call — not one statement per column, and not a sequence of
// separate statements. A single ALTER TABLE statement is atomic in InnoDB — it
// either fully applies or fully rolls back — so there is never a window where a
// column's old FK has been dropped but the new one hasn't landed yet, AND there is
// no second DDL statement whose safety would depend on a lock surviving between
// two ALTERs: in the common case (both columns need hardening on first run),
// there is exactly one ALTER TABLE for the whole method. Verified directly:
// forcing a combined statement to fail (e.g. a duplicate constraint name) left
// every column's original FK completely untouched.
//
// MariaDB will not let a single statement DROP and ADD a FOREIGN KEY under the same
// constraint name (errno 121, duplicate key), so the RESTRICT-hardened constraint
// uses a distinct name (suffixed "_restrict") from the original Laravel-convention
// name. currentForeignKey() below looks up the FK by column, not by name, so this
// naming detail doesn't affect idempotency, rerun-safety, or the equally-atomic
// down() path back to the original name.
//
// The whole check-then-alter sequence for each column is additionally wrapped in an
// explicit table lock (vehicle_photos WRITE, vehicles/users READ) as defense in
// depth against a concurrent write between the "is this already correct" check and
// the ALTER — reducing spurious failures under real concurrent load — but the core
// safety property (never left without adequate FK protection) no longer depends on
// the lock: it comes from the ALTER TABLE statement's own atomicity.
return new class extends Migration
{
    private const TABLE = 'vehicle_photos';

    public function up(): void
    {
        if ($this->isSqlite()) {
            $this->assertNoNullUploaders();
            $this->assertNoOrphanedForeignKeyValues('vehicle_id', 'vehicles');
            $this->assertNoOrphanedForeignKeyValues('uploaded_by', 'users');

            // SQLite has no incremental ALTER TABLE for constraints: Laravel recreates
            // the whole table under one transaction per Schema::table() call, so this
            // is already atomic and doesn't need the raw-SQL approach below.
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropForeign(['vehicle_id']);
                $table->dropForeign(['uploaded_by']);
            });

            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('uploaded_by')->nullable(false)->change();
                $table->foreign('vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
                $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
            });

            return;
        }

        $this->withTableLock(function () {
            $vehicleIdNeedsFix = ! $this->isForeignKeyCorrect('vehicle_id', 'vehicles', 'RESTRICT');
            $uploadedByNeedsFix = ! $this->isForeignKeyCorrect('uploaded_by', 'users', 'RESTRICT') || $this->columnIsNullable('uploaded_by');

            // Fail fast: validate every precondition for BOTH columns that need
            // fixing before performing DDL for either one. Without this, a run
            // that's already doomed to fail on known-bad uploaded_by data would
            // still apply a real, unrelated fix to vehicle_id first, only to
            // abort on uploaded_by afterwards: a needless partial application of
            // a migration that could never have fully succeeded in the first
            // place.
            if ($vehicleIdNeedsFix) {
                $this->assertNoOrphanedForeignKeyValues('vehicle_id', 'vehicles');
            }

            if ($uploadedByNeedsFix) {
                $this->assertNoNullUploaders();
                $this->assertNoOrphanedForeignKeyValues('uploaded_by', 'users');
            }

            $fixes = [];

            if ($vehicleIdNeedsFix) {
                $fixes[] = [
                    'column' => 'vehicle_id',
                    'referencedTable' => 'vehicles',
                    'newConstraintName' => 'vehicle_photos_vehicle_id_foreign_restrict',
                    'onDeleteClause' => 'ON DELETE RESTRICT',
                ];
            }

            if ($uploadedByNeedsFix) {
                $fixes[] = [
                    'column' => 'uploaded_by',
                    'referencedTable' => 'users',
                    'newConstraintName' => 'vehicle_photos_uploaded_by_foreign_restrict',
                    'onDeleteClause' => 'ON DELETE RESTRICT',
                    'modifyColumnClause' => 'MODIFY `uploaded_by` BIGINT UNSIGNED NOT NULL',
                ];
            }

            // Both fixes (when both are needed, the common first-run case) are
            // issued as ONE combined ALTER TABLE statement, not two sequential
            // ones — see replaceForeignKeysAtomically(). This removes any
            // dependency on a table lock surviving across multiple DDL
            // statements: there is at most one ALTER TABLE for the whole method.
            $this->replaceForeignKeysAtomically($fixes);
        });
    }

    public function down(): void
    {
        if ($this->isSqlite()) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropForeign(['vehicle_id']);
                $table->dropForeign(['uploaded_by']);
            });

            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('uploaded_by')->nullable()->change();
                $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
                $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            });

            return;
        }

        $this->withTableLock(function () {
            $vehicleIdNeedsFix = ! $this->isForeignKeyCorrect('vehicle_id', 'vehicles', 'CASCADE');
            $uploadedByNeedsFix = ! $this->isForeignKeyCorrect('uploaded_by', 'users', 'SET NULL') || ! $this->columnIsNullable('uploaded_by');

            // Same fail-fast discipline as up(): validate both columns before
            // touching either, so a doomed run never partially applies.
            if ($vehicleIdNeedsFix) {
                $this->assertNoOrphanedForeignKeyValues('vehicle_id', 'vehicles');
            }

            if ($uploadedByNeedsFix) {
                $this->assertNoOrphanedForeignKeyValues('uploaded_by', 'users');
            }

            $fixes = [];

            if ($vehicleIdNeedsFix) {
                $fixes[] = [
                    'column' => 'vehicle_id',
                    'referencedTable' => 'vehicles',
                    'newConstraintName' => 'vehicle_photos_vehicle_id_foreign',
                    'onDeleteClause' => 'ON DELETE CASCADE',
                ];
            }

            if ($uploadedByNeedsFix) {
                $fixes[] = [
                    'column' => 'uploaded_by',
                    'referencedTable' => 'users',
                    'newConstraintName' => 'vehicle_photos_uploaded_by_foreign',
                    'onDeleteClause' => 'ON DELETE SET NULL',
                    'modifyColumnClause' => 'MODIFY `uploaded_by` BIGINT UNSIGNED NULL',
                ];
            }

            // Same single-combined-statement discipline as up(): at most one
            // ALTER TABLE for the whole rollback.
            $this->replaceForeignKeysAtomically($fixes);
        });
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    /**
     * Hold vehicle_photos WRITE + vehicles/users READ for the duration of
     * $callback. Defense in depth only (see class-level comment): reduces spurious
     * failures under real concurrent load by serializing writes around the
     * check-then-alter sequence, but the core "never left without adequate FK
     * protection" guarantee comes from replaceForeignKeysAtomically()'s use of a
     * single atomic ALTER TABLE statement per call, not from this lock — and
     * because that method combines every column that needs fixing into that one
     * statement, up()/down() never issue more than one ALTER TABLE each, so there
     * is no second DDL statement whose safety would depend on this lock spanning
     * multiple ALTERs in the first place.
     *
     * Verified against a live MariaDB 10.11.18 server (this project's actual DB):
     * a second connection's INSERT into vehicle_photos blocks while this lock is
     * held and hits lock_wait_timeout, for the duration of a combined multi-column
     * ALTER TABLE statement.
     */
    private function withTableLock(\Closure $callback): void
    {
        DB::statement('LOCK TABLES '.self::TABLE.' WRITE, vehicles READ, users READ');

        try {
            $callback();
        } finally {
            DB::statement('UNLOCK TABLES');
        }
    }

    private function isForeignKeyCorrect(string $column, string $referencedTable, string $expectedDeleteRule): bool
    {
        $current = $this->currentForeignKey($column);

        return $current
            && strtoupper($current->DELETE_RULE) === $expectedDeleteRule
            && $current->REFERENCED_TABLE_NAME === $referencedTable;
    }

    /**
     * Replace whatever FK currently exists on each fixed column (if any) with a new
     * one, all in a SINGLE ALTER TABLE statement combining every column's DROP
     * FOREIGN KEY, optional nullability change, and ADD CONSTRAINT clause. This is
     * what actually removes the "does the table lock survive across multiple ALTER
     * TABLE statements" question for the common case where both vehicle_id and
     * uploaded_by need fixing: there is only ever at most one ALTER TABLE per
     * up()/down() call, so there's no second statement for a released lock (were
     * it ever released, which separate testing shows it isn't) to matter for.
     *
     * InnoDB executes one ALTER TABLE statement, however many clauses it has, as
     * one atomic operation: it either fully lands or fully rolls back, leaving
     * every listed column exactly as it was before if anything in it fails —
     * there's no partial-application state where some columns' fixes landed and
     * others didn't.
     *
     * Each $fixes entry is ['column', 'referencedTable', 'newConstraintName',
     * 'onDeleteClause', 'modifyColumnClause'? ]. newConstraintName is a fixed,
     * deliberately-chosen name per call site, but the CURRENT constraint's actual
     * name isn't guaranteed to differ from it: earlier revisions of this same
     * migration (and the SQLite code path above, which lets Laravel's Blueprint
     * pick its own default name) hardened columns using Laravel's plain default
     * naming convention rather than this file's current suffixed names. If an
     * environment's column was hardened by one of those, its current name can
     * collide with the desired new name. MariaDB rejects a single statement that
     * DROPs and ADDs a FOREIGN KEY under the identical name (errno 121, duplicate
     * key) — so detect that collision per column and disambiguate at runtime
     * rather than assuming it never happens.
     */
    private function replaceForeignKeysAtomically(array $fixes): void
    {
        if ($fixes === []) {
            return;
        }

        $clauses = [];

        foreach ($fixes as $fix) {
            $current = $this->currentForeignKey($fix['column']);
            $newConstraintName = $fix['newConstraintName'];

            if ($current && $current->CONSTRAINT_NAME === $newConstraintName) {
                $newConstraintName .= '_'.substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
            }

            if ($current) {
                $clauses[] = 'DROP FOREIGN KEY `'.$current->CONSTRAINT_NAME.'`';
            }

            if (! empty($fix['modifyColumnClause'])) {
                $clauses[] = $fix['modifyColumnClause'];
            }

            $clauses[] = "ADD CONSTRAINT `{$newConstraintName}` FOREIGN KEY (`{$fix['column']}`) REFERENCES `{$fix['referencedTable']}` (`id`) {$fix['onDeleteClause']}";
        }

        DB::statement('ALTER TABLE `'.self::TABLE.'` '.implode(', ', $clauses));
    }

    private function assertNoNullUploaders(): void
    {
        if (DB::table(self::TABLE)->whereNull('uploaded_by')->exists()) {
            throw new \RuntimeException(
                'vehicle_photos.uploaded_by 仍有 NULL 值，無法安全套用 NOT NULL 限制，請先人工確認來源後再重新執行本次 migration。'
            );
        }
    }

    /**
     * MySQL/MariaDB validates ALL existing rows against the referenced table when
     * adding a foreign key, regardless of its ON DELETE action — so a row whose
     * $column value doesn't match any id in $referencedTable would make the ADD
     * FOREIGN KEY clause in replaceForeignKeyAtomically() fail (and, per that
     * method's atomicity, the whole combined ALTER TABLE statement fails cleanly
     * with $column untouched). Catching that here first gives a clear, business-
     * readable error instead of a raw constraint-violation message.
     */
    private function assertNoOrphanedForeignKeyValues(string $column, string $referencedTable): void
    {
        $orphaned = DB::table(self::TABLE)
            ->whereNotNull($column)
            ->whereNotIn($column, function ($query) use ($referencedTable) {
                $query->select('id')->from($referencedTable);
            })
            ->exists();

        if ($orphaned) {
            throw new \RuntimeException(
                sprintf(
                    'vehicle_photos.%s 存在指向不存在的 %s 資料列（孤兒外鍵值），無法安全套用外鍵限制，請先人工修正孤兒資料後再重新執行本次 migration。',
                    $column,
                    $referencedTable
                )
            );
        }
    }

    private function currentForeignKey(string $column): ?object
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE as kcu')
            ->join('information_schema.REFERENTIAL_CONSTRAINTS as rc', function ($join) {
                $join->on('rc.CONSTRAINT_NAME', '=', 'kcu.CONSTRAINT_NAME')
                    ->on('rc.CONSTRAINT_SCHEMA', '=', 'kcu.CONSTRAINT_SCHEMA');
            })
            ->where('kcu.CONSTRAINT_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('kcu.TABLE_NAME', self::TABLE)
            ->where('kcu.COLUMN_NAME', $column)
            ->whereNotNull('kcu.REFERENCED_TABLE_NAME')
            ->select('rc.CONSTRAINT_NAME', 'rc.DELETE_RULE', 'kcu.REFERENCED_TABLE_NAME')
            ->first();
    }

    private function columnIsNullable(string $column): bool
    {
        $row = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('COLUMN_NAME', $column)
            ->first();

        return $row !== null && strtoupper($row->IS_NULLABLE) === 'YES';
    }
};
