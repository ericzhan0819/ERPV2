<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 此為 forward-only 修正：已執行舊 migration 的環境不會重跑原內容，因此在此修正實際 schema。
// vehicle_id 原本使用 CASCADE，刪車可能在清理檔案前刪掉照片列；uploaded_by 原本可為 NULL 且
// 使用 SET NULL，刪使用者會失去上傳歸屬。兩者都改為 RESTRICT，與服務層保護規則一致。
//
// 每次 up()/down() 將所有必要調整合成一條 ALTER TABLE，而非分欄位執行。InnoDB 的單一 ALTER
// 是原子操作，會完整套用或完整回滾，不會出現已移除舊外鍵、卻尚未加入新外鍵的空窗。
//
// MariaDB 不能在同一條指令用相同名稱刪除後再新增外鍵，因此強化版外鍵加上 _restrict 後綴。
// currentForeignKey() 依欄位查詢外鍵，名稱差異不影響重跑安全性或 down() 的原子性。
//
// 檢查後再調整的整段流程另以資料表鎖保護，降低並行寫入造成的偶發失敗；核心安全性仍來自
// 單一 ALTER TABLE 的原子性，而非依賴鎖在多條 DDL 之間持續存在。
return new class extends Migration
{
    private const TABLE = 'vehicle_photos';

    public function up(): void
    {
        if ($this->isSqlite()) {
            $this->assertNoNullUploaders();
            $this->assertNoOrphanedForeignKeyValues('vehicle_id', 'vehicles');
            $this->assertNoOrphanedForeignKeyValues('uploaded_by', 'users');

            // SQLite 無法逐步修改外鍵；Laravel 每次 Schema::table() 都在交易中重建整張表，
            // 已具原子性，因此不需要下方 MySQL/MariaDB 的原生 SQL 作法。
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

            // 先驗證兩欄的所有前提，再執行任一 DDL。若 uploaded_by 已知無法修正，
            // 就不應先修改無關的 vehicle_id，避免不可能完整成功的 migration 留下部分結果。
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

            // 兩項修正合成一條 ALTER TABLE，不依賴資料表鎖跨越多條 DDL；整個方法最多只會執行一條。
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

            // 與 up() 相同，先驗證兩欄再修改，避免必定失敗的回滾只完成一部分。
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

            // 與 up() 相同，整個回滾最多只會執行一條合併的 ALTER TABLE。
            $this->replaceForeignKeysAtomically($fixes);
        });
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    /**
     * 執行 callback 期間鎖住 vehicle_photos 的寫入及 vehicles/users 的讀取，讓檢查與調整
     * 不會被並行寫入打斷。此鎖只是額外保護；真正保證外鍵不會短暫失效的是單一原子 ALTER TABLE。
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
     * 將每個需要修正欄位的舊外鍵、可選的 NULL 調整及新外鍵合併在一條 ALTER TABLE 中。
     * InnoDB 會將整條指令原子執行：任何子項失敗都完整回滾，不會只套用部分欄位。
     *
     * 若既有外鍵名稱剛好與要新增的名稱相同，MariaDB 無法在同一條指令內先刪再加同名外鍵，
     * 因此執行時加上隨機後綴避開衝突。
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
     * MySQL/MariaDB 新增外鍵時會檢查所有既有資料，與 ON DELETE 規則無關。若 $column 指向的
     * $referencedTable 資料不存在，整條 ALTER TABLE 會失敗；先在這裡檢查可回傳清楚訊息。
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
