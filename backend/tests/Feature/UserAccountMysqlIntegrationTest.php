<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserAccountMysqlIntegrationTest extends TestCase
{
    private const USERNAME_UNIQUE_INDEX = 'users_username_unique';

    public function test_real_mysql_enforces_case_insensitive_username_and_migration_rollback_order(): void
    {
        $this->prepareDisposableMysqlDatabase();

        DB::table('users')->insert([
            'name' => '第一位使用者',
            'email' => 'first@example.com',
            'username' => 'Eric',
            'password' => 'not-used-in-this-schema-test',
        ]);

        try {
            DB::table('users')->insert([
                'name' => '第二位使用者',
                'email' => 'second@example.com',
                'username' => 'eric',
                'password' => 'not-used-in-this-schema-test',
            ]);
            $this->fail('MariaDB case-insensitive collation 必須拒絕大小寫不同的重複 username。');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('users')->where('username', 'ERIC')->count());
        }

        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('users', 'username'));
        $this->assertFalse(Schema::hasColumn('users', 'must_change_password'));
        $this->assertFalse(Schema::hasIndex('users', self::USERNAME_UNIQUE_INDEX));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasColumns('users', ['username', 'must_change_password']));
        $this->assertTrue(Schema::hasIndex('users', self::USERNAME_UNIQUE_INDEX));
    }

    private function prepareDisposableMysqlDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('此測試需要 MySQL/MariaDB；SQLite 的 unique collation 語意不同。');
        }

        if (env('RUN_MYSQL_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped(
                '此測試會 migrate:fresh 目前測試資料庫；請只在可拋棄 MySQL/MariaDB schema 上設定 RUN_MYSQL_CONCURRENCY_TESTS=1 後執行。'
            );
        }

        $connection = DB::connection();
        $connectionName = $connection->getName();
        $databaseName = (string) $connection->getDatabaseName();
        $allowedConnection = (string) env('MYSQL_CONCURRENCY_TEST_CONNECTION', '');
        $allowedDatabase = (string) env('MYSQL_CONCURRENCY_TEST_DATABASE', '');

        $this->assertSame('testing', (string) config('app.env'), '拒絕執行 migrate:fresh：APP_ENV 必須是 testing。');
        $this->assertTrue(app()->environment('testing'), '拒絕執行 migrate:fresh：Laravel application environment 必須是 testing。');
        $this->assertTrue(app()->runningUnitTests(), '拒絕執行 migrate:fresh：只能由 PHPUnit 測試程序執行。');
        $this->assertNotSame('', $allowedConnection, '拒絕執行 migrate:fresh：必須設定專用測試連線 allowlist。');
        $this->assertSame($allowedConnection, $connectionName, '拒絕執行 migrate:fresh：目前 DB connection 不在 allowlist。');
        $this->assertNotSame('', $allowedDatabase, '拒絕執行 migrate:fresh：必須設定可拋棄測試資料庫 allowlist。');
        $this->assertSame($allowedDatabase, $databaseName, '拒絕執行 migrate:fresh：目前 DB database 不在 allowlist。');
        $this->assertTrue(
            self::isClearlyDisposableTestDatabaseName($databaseName),
            "拒絕執行 migrate:fresh：資料庫名稱 [{$databaseName}] 必須明確包含 test/testing/phpunit/ci，且不得包含 production/staging/dev/local。",
        );

        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    private static function isClearlyDisposableTestDatabaseName(string $databaseName): bool
    {
        $normalized = strtolower(trim($databaseName));

        return preg_match('/(^|[_-])(test|testing|phpunit|ci)([_-]|$)/', $normalized) === 1
            && preg_match('/prod|production|live|staging|stage|dev|development|local/', $normalized) !== 1;
    }
}
