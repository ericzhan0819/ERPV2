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
// MySQL/MariaDB DDL is not transactional across statements, so a crash or connection
// drop mid-migration could otherwise leave both foreign keys dropped and neither
// re-added. Every step checks the CURRENT constraint definition (referenced table +
// delete rule), not just "does a constraint with this name exist": if the FK is
// already correct it is left untouched entirely, so re-running this migration on an
// already-hardened schema performs no drop/add at all and never reopens the
// unconstrained window. If it's missing or points at the wrong rule, only that one
// column's FK is dropped-and-recreated, one column at a time.
return new class extends Migration
{
    private const TABLE = 'vehicle_photos';

    public function up(): void
    {
        if (DB::table(self::TABLE)->whereNull('uploaded_by')->exists()) {
            throw new \RuntimeException(
                'vehicle_photos.uploaded_by 仍有 NULL 值，無法安全套用 NOT NULL 限制，請先人工確認來源後再重新執行本次 migration。'
            );
        }

        if ($this->isSqlite()) {
            // SQLite has no incremental ALTER TABLE for constraints: Laravel recreates
            // the whole table under one transaction per Schema::table() call, so this
            // is already atomic and doesn't need the guards below.
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

        // Drop mismatched FKs BEFORE altering nullability: MySQL refuses to change a
        // column to NOT NULL while a SET NULL foreign key still references it
        // (error 1830), so the stale uploaded_by FK has to go first.
        $this->dropForeignKeyIfMismatched('vehicle_id', 'vehicles', 'RESTRICT');
        $this->dropForeignKeyIfMismatched('uploaded_by', 'users', 'RESTRICT');

        if ($this->columnIsNullable('uploaded_by')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('uploaded_by')->nullable(false)->change();
            });
        }

        $this->addForeignKeyIfMissing('vehicle_id', function (Blueprint $table) {
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
        });

        $this->addForeignKeyIfMissing('uploaded_by', function (Blueprint $table) {
            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
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

        $this->dropForeignKeyIfMismatched('vehicle_id', 'vehicles', 'CASCADE');
        $this->dropForeignKeyIfMismatched('uploaded_by', 'users', 'SET NULL');

        if (! $this->columnIsNullable('uploaded_by')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('uploaded_by')->nullable()->change();
            });
        }

        $this->addForeignKeyIfMissing('vehicle_id', function (Blueprint $table) {
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
        });

        $this->addForeignKeyIfMissing('uploaded_by', function (Blueprint $table) {
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    /**
     * Drop the FK currently on $column only if it doesn't already match the desired
     * (referencedTable, deleteRule) state. No-ops entirely when already correct, so a
     * re-run on an already-hardened schema performs no drop at all here.
     */
    private function dropForeignKeyIfMismatched(string $column, string $referencedTable, string $expectedDeleteRule): void
    {
        $current = $this->currentForeignKey($column);

        if (! $current) {
            return;
        }

        if (strtoupper($current->DELETE_RULE) === $expectedDeleteRule
            && $current->REFERENCED_TABLE_NAME === $referencedTable) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($current) {
            $table->dropForeign($current->CONSTRAINT_NAME);
        });
    }

    /**
     * Add the FK via $addCallback only if $column currently has no foreign key at
     * all. No-ops entirely when one is already present, so a re-run on an
     * already-hardened schema performs no add at all here.
     */
    private function addForeignKeyIfMissing(string $column, \Closure $addCallback): void
    {
        if ($this->currentForeignKey($column) !== null) {
            return;
        }

        Schema::table(self::TABLE, $addCallback);
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
