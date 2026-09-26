<?php

namespace Tests\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait RunsMysqlConcurrencyTests
{
    private const CHILD_HANDSHAKE_TIMEOUT_SECONDS = 10;

    private const CHILD_EXIT_TIMEOUT_SECONDS = 10;

    private const CHILD_STOP_TIMEOUT_SECONDS = 3;

    private const CHILD_WAIT_POLL_MICROSECONDS = 100000;

    private const SOCKET_TIMEOUT_EXERCISE_MICROSECONDS = 100000;

    private static function isIdempotencyMissLookup(QueryExecuted $query, string $idempotencyKey): bool
    {
        $sql = strtolower($query->sql);

        return str_starts_with($sql, 'select')
            && str_contains($sql, 'money_entries')
            && str_contains($sql, 'idempotency_key')
            && in_array($idempotencyKey, $query->bindings, true);
    }

    private function skipUnlessRealMysqlConcurrencyTestCanRun(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('此測試需要 MySQL/MariaDB；SQLite 無法重現 REPEATABLE READ duplicate-key 競態。');
        }

        if (env('RUN_MYSQL_CONCURRENCY_TESTS') !== '1') {
            $this->markTestSkipped(
                '此測試會 migrate:fresh 目前測試資料庫；請只在可拋棄 MySQL/MariaDB schema 上設定 RUN_MYSQL_CONCURRENCY_TESTS=1 後執行。'
            );
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('此測試需要 pcntl 與 posix extension 才能建立真正的兩個 PHP 請求行程並安全清理 child。');
        }
    }

    private function assertSafeToFreshMigrateMysqlConcurrencyDatabase(): void
    {
        $connection = DB::connection();
        $connectionName = $connection->getName();
        $databaseName = (string) $connection->getDatabaseName();
        $allowedConnection = (string) env('MYSQL_CONCURRENCY_TEST_CONNECTION', '');
        $allowedDatabase = (string) env('MYSQL_CONCURRENCY_TEST_DATABASE', '');

        $this->assertSame('testing', (string) config('app.env'), '拒絕執行 migrate:fresh：APP_ENV 必須是 testing。');
        $this->assertTrue(app()->environment('testing'), '拒絕執行 migrate:fresh：Laravel application environment 必須是 testing。');
        $this->assertTrue(app()->runningUnitTests(), '拒絕執行 migrate:fresh：只能由 PHPUnit 測試程序執行。');
        $this->assertNotSame('', $allowedConnection, '拒絕執行 migrate:fresh：必須設定 MYSQL_CONCURRENCY_TEST_CONNECTION 為專用測試連線名稱。');
        $this->assertSame($allowedConnection, $connectionName, '拒絕執行 migrate:fresh：目前 DB connection 未符合 MYSQL_CONCURRENCY_TEST_CONNECTION allowlist。');
        $this->assertNotSame('', $allowedDatabase, '拒絕執行 migrate:fresh：必須設定 MYSQL_CONCURRENCY_TEST_DATABASE 為可拋棄測試資料庫名稱。');
        $this->assertSame($allowedDatabase, $databaseName, '拒絕執行 migrate:fresh：目前 DB database 未符合 MYSQL_CONCURRENCY_TEST_DATABASE allowlist。');
        $this->assertTrue(
            self::isClearlyDisposableTestDatabaseName($databaseName),
            "拒絕執行 migrate:fresh：資料庫名稱 [{$databaseName}] 必須明確包含 test/testing/phpunit/ci，且不得包含 prod/production/live/staging/dev/local。"
        );
    }

    private function waitForChildOrStop(
        int $pid,
        int $exitTimeoutSeconds = self::CHILD_EXIT_TIMEOUT_SECONDS,
        int $stopTimeoutSeconds = self::CHILD_STOP_TIMEOUT_SECONDS
    ): int {
        $status = $this->waitForChild($pid, $exitTimeoutSeconds);
        if ($status !== null) {
            return $status;
        }

        $this->signalChild($pid, defined('SIGTERM') ? SIGTERM : 15);
        $status = $this->waitForChild($pid, $stopTimeoutSeconds);
        if ($status !== null) {
            return $status;
        }

        $this->signalChild($pid, defined('SIGKILL') ? SIGKILL : 9);
        $status = $this->waitForChild($pid, $stopTimeoutSeconds);
        if ($status !== null) {
            return $status;
        }

        throw new RuntimeException("Child process {$pid} did not exit after bounded wait and stop signals.");
    }

    private function waitForChild(int $pid, int $timeoutSeconds): ?int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result === $pid) {
                return $status;
            }

            if ($result === -1) {
                return 0;
            }

            usleep(self::CHILD_WAIT_POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function signalChild(int $pid, int $signal): void
    {
        if (! function_exists('posix_kill')) {
            throw new RuntimeException('posix_kill is required to stop a blocked child process.');
        }

        @posix_kill($pid, $signal);
    }

    private static function isClearlyDisposableTestDatabaseName(string $databaseName): bool
    {
        $normalized = strtolower(trim($databaseName));

        if ($normalized === '' || in_array($normalized, ['mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
            return false;
        }

        if (preg_match('/(^|[_-])(prod|production|live|staging|dev|local)([_-]|$)/', $normalized) === 1) {
            return false;
        }

        return preg_match('/(^|[_-])(test|testing|phpunit|ci)([_-]|$)/', $normalized) === 1;
    }

    private function formatChildStatus(int $status): string
    {
        if (pcntl_wifexited($status)) {
            return 'exited('.pcntl_wexitstatus($status).')';
        }

        if (pcntl_wifsignaled($status)) {
            return 'signaled('.pcntl_wtermsig($status).')';
        }

        return 'unknown('.$status.')';
    }

    /**
     * @return array<string, mixed>
     */
    private function readChildResult(string $resultPath): array
    {
        if (! is_file($resultPath)) {
            return ['ok' => false, 'message' => 'child did not write a result file'];
        }

        $contents = file_get_contents($resultPath);
        if ($contents === false || $contents === '') {
            return ['ok' => false, 'message' => 'child result file is empty or unreadable'];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'message' => 'child result file is not valid JSON'];
    }
}
