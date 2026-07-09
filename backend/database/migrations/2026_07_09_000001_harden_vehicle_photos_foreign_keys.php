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
// Each column's fix (drop the old FK, adjust nullability if needed, add the new FK)
// is issued as ONE combined ALTER TABLE statement, not a sequence of separate
// statements. A single ALTER TABLE statement is atomic in InnoDB — it either fully
// applies or fully rolls back — so there is never a window where a column's old FK
// has been dropped but the new one hasn't landed yet: that failure mode is
// structurally impossible here, not just guarded against. Verified directly: forcing
// a combined statement to fail (e.g. a duplicate constraint name) left the column's
// original FK completely untouched.
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
            // still apply a real, unrelated fix to vehicle_id first (its ALTER
            // TABLE statement being atomic doesn't prevent that — atomicity is
            // per-column, not across columns), only to abort on uploaded_by
            // afterwards: a needless partial application of a migration that
            // could never have fully succeeded in the first place.
            if ($vehicleIdNeedsFix) {
                $this->assertNoOrphanedForeignKeyValues('vehicle_id', 'vehicles');
            }

            if ($uploadedByNeedsFix) {
                $this->assertNoNullUploaders();
                $this->assertNoOrphanedForeignKeyValues('uploaded_by', 'users');
            }

            if ($vehicleIdNeedsFix) {
                $this->replaceForeignKeyAtomically(
                    column: 'vehicle_id',
                    referencedTable: 'vehicles',
                    newConstraintName: 'vehicle_photos_vehicle_id_foreign_restrict',
                    onDeleteClause: 'ON DELETE RESTRICT',
                );
            }

            if ($uploadedByNeedsFix) {
                $this->replaceForeignKeyAtomically(
                    column: 'uploaded_by',
                    referencedTable: 'users',
                    newConstraintName: 'vehicle_photos_uploaded_by_foreign_restrict',
                    onDeleteClause: 'ON DELETE RESTRICT',
                    modifyColumnClause: 'MODIFY `uploaded_by` BIGINT UNSIGNED NOT NULL',
                );
            }
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

            if ($vehicleIdNeedsFix) {
                $this->replaceForeignKeyAtomically(
                    column: 'vehicle_id',
                    referencedTable: 'vehicles',
                    newConstraintName: 'vehicle_photos_vehicle_id_foreign',
                    onDeleteClause: 'ON DELETE CASCADE',
                );
            }

            if ($uploadedByNeedsFix) {
                $this->replaceForeignKeyAtomically(
                    column: 'uploaded_by',
                    referencedTable: 'users',
                    newConstraintName: 'vehicle_photos_uploaded_by_foreign',
                    onDeleteClause: 'ON DELETE SET NULL',
                    modifyColumnClause: 'MODIFY `uploaded_by` BIGINT UNSIGNED NULL',
                );
            }
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
     * protection" guarantee comes from replaceForeignKeyAtomically()'s use of a
     * single atomic ALTER TABLE statement, not from this lock.
     *
     * Verified against a live MariaDB 10.11.18 server (this project's actual DB):
     * a second connection's INSERT into vehicle_photos blocks while this lock is
     * held and hits lock_wait_timeout. Specifically re-verified for the case where
     * up()/down() call this once and issue TWO sequential ALTER TABLE statements
     * inside it (one per column, both via replaceForeignKeyAtomically()): a
     * diagnostic run of the exact same statements with an artificial multi-second
     * pause inserted between the vehicle_id ALTER and the uploaded_by ALTER, with a
     * second process hammering INSERT INTO vehicle_photos throughout, showed writes
     * blocked continuously — including attempts that landed squarely inside that
     * pause, nowhere near either ALTER statement — until UNLOCK TABLES ran after
     * the second ALTER completed. The lock is held once for the whole callback and
     * is not released or re-acquired between the two ALTERs.
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
     * Replace whatever FK currently exists on $column (if any) with a new one, in a
     * single ALTER TABLE statement combining DROP FOREIGN KEY, an optional column
     * nullability change, and ADD CONSTRAINT. InnoDB executes this as one atomic
     * operation: it cannot leave the column with the old FK dropped and the new one
     * not yet added, because from the database's point of view there is no
     * intermediate state to observe — the statement either fully lands or fully
     * fails, leaving $column exactly as it was before.
     *
     * $newConstraintName is a fixed, deliberately-chosen name per call site, but the
     * CURRENT constraint's actual name isn't guaranteed to differ from it: earlier
     * revisions of this same migration (and the SQLite code path above, which lets
     * Laravel's Blueprint pick its own default name) hardened columns using
     * Laravel's plain default naming convention rather than this file's current
     * suffixed names. If an environment's column was hardened by one of those, its
     * current name can collide with $newConstraintName here. MariaDB rejects a
     * single statement that DROPs and ADDs a FOREIGN KEY under the identical name
     * (errno 121, duplicate key) — so detect that collision and disambiguate the
     * new name at runtime rather than assuming it never happens.
     */
    private function replaceForeignKeyAtomically(
        string $column,
        string $referencedTable,
        string $newConstraintName,
        string $onDeleteClause,
        ?string $modifyColumnClause = null,
    ): void {
        $current = $this->currentForeignKey($column);

        if ($current && $current->CONSTRAINT_NAME === $newConstraintName) {
            $newConstraintName .= '_'.substr(md5(uniqid((string) mt_rand(), true)), 0, 8);
        }

        $clauses = [];

        if ($current) {
            $clauses[] = 'DROP FOREIGN KEY `'.$current->CONSTRAINT_NAME.'`';
        }

        if ($modifyColumnClause) {
            $clauses[] = $modifyColumnClause;
        }

        $clauses[] = "ADD CONSTRAINT `{$newConstraintName}` FOREIGN KEY (`{$column}`) REFERENCES `{$referencedTable}` (`id`) {$onDeleteClause}";

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
