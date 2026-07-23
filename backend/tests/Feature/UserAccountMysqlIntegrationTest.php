<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserAccountMysqlIntegrationTest extends TestCase
{
    private const CHILD_TIMEOUT_SECONDS = 10;

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

    public function test_two_real_mysql_connections_racing_for_one_username_return_validation_to_loser(): void
    {
        $this->prepareDisposableMysqlDatabase(requireProcessControl: true);

        $winner = User::factory()->create();
        $loser = User::factory()->create();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            $this->markTestSkipped('此環境不支援 stream_socket_pair，無法建立跨連線同步屏障。');
        }

        [$parentSocket, $childSocket] = $sockets;
        $resultPath = tempnam(sys_get_temp_dir(), 'erpv2-username-race-');

        if ($resultPath === false) {
            $this->fail('無法建立 username 競態測試結果檔。');
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            @unlink($resultPath);
            $this->fail('pcntl_fork 失敗，無法建立真正的第二個 username 寫入請求。');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            $this->runUsernameRaceLoser($childSocket, $resultPath, $loser->id);
        }

        fclose($childSocket);
        stream_set_timeout($parentSocket, self::CHILD_TIMEOUT_SECONDS);

        try {
            $this->assertSame('R', fread($parentSocket, 1), '競態 child 未完成獨立資料庫連線初始化。');

            DB::purge();
            DB::reconnect();
            DB::beginTransaction();
            app(UserService::class)->setUsername(
                User::query()->findOrFail($winner->id),
                ' Race.Owner ',
            );

            fwrite($parentSocket, 'G');
            $this->assertSame('S', fread($parentSocket, 1), '競態 child 未開始 username 寫入。');

            stream_set_blocking($parentSocket, false);
            usleep(300000);
            $this->assertSame('', fread($parentSocket, 1), 'winner 尚未提交前，loser 不應先完成 unique 檢查。');

            DB::commit();
            stream_set_blocking($parentSocket, true);
            stream_set_timeout($parentSocket, self::CHILD_TIMEOUT_SECONDS);
            $this->assertSame('D', fread($parentSocket, 1), 'winner 提交後，loser 未在時限內完成。');

            $status = $this->waitForChild($pid);
            $pid = 0;
            $result = json_decode((string) file_get_contents($resultPath), true);

            $this->assertTrue(
                pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                'loser child 應將 duplicate key 轉為 validation error。Result: '.json_encode($result, JSON_UNESCAPED_UNICODE),
            );
            $this->assertSame('validation', $result['result'] ?? null);
            $this->assertSame(['username'], $result['error_fields'] ?? null);
            $this->assertSame('race.owner', User::query()->findOrFail($winner->id)->username);
            $this->assertNull(User::query()->findOrFail($loser->id)->username);
            $this->assertSame(1, User::query()->where('username', 'race.owner')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }

            if ($pid > 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }

            @unlink($resultPath);
        }
    }

    private function prepareDisposableMysqlDatabase(bool $requireProcessControl = false): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('此測試需要 MySQL/MariaDB；SQLite 的 unique collation 語意不同。');
        }

        if (env('RUN_MYSQL_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped(
                '此測試會 migrate:fresh 目前測試資料庫；請只在可拋棄 MySQL/MariaDB schema 上設定 RUN_MYSQL_CONCURRENCY_TESTS=1 後執行。'
            );
        }

        if ($requireProcessControl && (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid') || ! function_exists('posix_kill'))) {
            $this->markTestSkipped('此環境缺少 pcntl／posix，無法執行真正的跨連線競態測試。');
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

    /**
     * @param  resource  $socket
     */
    private function runUsernameRaceLoser($socket, string $resultPath, int $userId): never
    {
        try {
            DB::purge();
            DB::reconnect();
            fwrite($socket, 'R');

            if (fread($socket, 1) !== 'G') {
                throw new \RuntimeException('未收到 username 競態開始訊號。');
            }

            fwrite($socket, 'S');

            try {
                app(UserService::class)->setUsername(
                    User::query()->findOrFail($userId),
                    'RACE.OWNER',
                );
                $result = ['result' => 'unexpected_success'];
            } catch (ValidationException $exception) {
                $result = [
                    'result' => 'validation',
                    'error_fields' => array_keys($exception->errors()),
                ];
            }

            file_put_contents($resultPath, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            fwrite($socket, 'D');
            fclose($socket);
            exit(0);
        } catch (\Throwable $exception) {
            file_put_contents($resultPath, json_encode([
                'result' => 'exception',
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
            fclose($socket);
            exit(1);
        }
    }

    private function waitForChild(int $pid): int
    {
        $deadline = microtime(true) + self::CHILD_TIMEOUT_SECONDS;

        do {
            $result = pcntl_waitpid($pid, $status, WNOHANG);

            if ($result === $pid) {
                return $status;
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        $this->fail('username 競態 child 未在時限內結束。');
    }

    private static function isClearlyDisposableTestDatabaseName(string $databaseName): bool
    {
        $normalized = strtolower(trim($databaseName));

        return preg_match('/(^|[_-])(test|testing|phpunit|ci)([_-]|$)/', $normalized) === 1
            && preg_match('/prod|production|live|staging|stage|dev|development|local/', $normalized) !== 1;
    }
}
