<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const USERNAME_UNIQUE_INDEX = 'users_username_unique';

    public function up(): void
    {
        // MySQL 的 DDL 可能在 migration 中途失敗後留下部分欄位，因此各步驟都可安全重跑。
        // 既有帳號不推測 username，也不強制改密碼；由後續明確的建立／重設流程設定狀態。
        if (! Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('username')->nullable()->after('email');
            });
        }

        if (! Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('must_change_password')->default(false)->after('password');
            });
        }

        if (! Schema::hasIndex('users', self::USERNAME_UNIQUE_INDEX)) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('username', self::USERNAME_UNIQUE_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('users', self::USERNAME_UNIQUE_INDEX)) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique(self::USERNAME_UNIQUE_INDEX);
            });
        }

        $columns = array_values(array_filter(
            ['username', 'must_change_password'],
            fn (string $column): bool => Schema::hasColumn('users', $column),
        ));

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
