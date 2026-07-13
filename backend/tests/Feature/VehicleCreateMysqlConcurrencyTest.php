<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class VehicleCreateMysqlConcurrencyTest extends TestCase
{
    private const CHILD_HANDSHAKE_TIMEOUT_SECONDS = 10;

    private const CHILD_EXIT_TIMEOUT_SECONDS = 10;

    private const CHILD_STOP_TIMEOUT_SECONDS = 3;

    private const CHILD_WAIT_POLL_MICROSECONDS = 100000;

    public function test_create_vehicle_duplicate_key_loser_replays_after_committed_mysql_winner(): void
    {
        $this->skipUnlessRealMysqlConcurrencyTestCanRun();

        $this->assertSafeToFreshMigrateMysqlConcurrencyDatabase();

        $this->artisan('migrate:fresh')->run();

        $user = User::factory()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $payload = [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'ABC-1234',
            'purchase_agent_id' => $user->id,
            'idempotency_key' => (string) Str::uuid(),
            'initial_purchase_payment' => [
                'amount' => 100000,
                'cash_account_id' => $cashAccount->id,
                'entry_date' => '2026-01-02',
            ],
        ];

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('此環境不支援 stream_socket_pair，無法建立父子行程同步屏障。');
        }

        $resultPath = tempnam(sys_get_temp_dir(), 'erpv2-vehicle-create-race-');
        if ($resultPath === false) {
            $this->fail('無法建立 MySQL 並行測試暫存結果檔。');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            @unlink($resultPath);
            $this->fail('pcntl_fork 失敗，無法建立真正的第二請求行程。');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runLosingCreateRequestInChild($sockets[1], $resultPath, $payload, $user->id);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], self::CHILD_HANDSHAKE_TIMEOUT_SECONDS);

        try {
            $signal = fread($sockets[0], 1);
            $metadata = stream_get_meta_data($sockets[0]);
            if ($signal !== 'M' || ($metadata['timed_out'] ?? false)) {
                fclose($sockets[0]);
                $this->waitForChildOrStop($pid);
                $pid = 0;
                $this->fail('輸家請求未在時限內完成 idempotency_key miss 並通知父行程。');
            }

            $winnerVehicle = app(VehicleService::class)->createVehicle($payload, $user->id);
            $this->assertNotNull($winnerVehicle->id);

            fwrite($sockets[0], 'C');
            fclose($sockets[0]);

            $status = $this->waitForChildOrStop($pid);
            $pid = 0;
            $childResult = $this->readChildResult($resultPath);

            $this->assertTrue(
                pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                '輸家請求應在 duplicate-key rollback 後 replay 成功。Child result: '.json_encode($childResult, JSON_UNESCAPED_UNICODE)
            );
            $this->assertSame(true, $childResult['ok'] ?? false);
            $this->assertSame($winnerVehicle->id, $childResult['vehicle_id'] ?? null);

            $this->assertSame(1, Vehicle::query()
                ->where('idempotency_key', $payload['idempotency_key'])
                ->count());
            $this->assertSame(1, MoneyEntry::query()
                ->where('vehicle_id', $winnerVehicle->id)
                ->where('category', '購車付款')
                ->count());
        } finally {
            if (is_resource($sockets[0])) {
                fclose($sockets[0]);
            }

            if ($pid > 0) {
                $this->waitForChildOrStop($pid);
            }

            @unlink($resultPath);
        }
    }

    public function test_different_idempotency_keys_created_concurrently_receive_distinct_stock_numbers(): void
    {
        $this->skipUnlessRealMysqlConcurrencyTestCanRun();
        $this->assertSafeToFreshMigrateMysqlConcurrencyDatabase();
        $this->artisan('migrate:fresh')->run();

        $user = User::factory()->create(['is_active' => true]);
        $firstPayload = [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => 'RACE-0001',
            'idempotency_key' => (string) Str::uuid(),
        ];
        $secondPayload = [
            'brand' => 'Honda',
            'model' => 'Civic',
            'license_plate' => 'RACE-0002',
            'idempotency_key' => (string) Str::uuid(),
        ];

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('此環境不支援 stream_socket_pair，無法建立父子行程同步屏障。');
        }

        $firstResultPath = tempnam(sys_get_temp_dir(), 'erpv2-stock-race-a-');
        $secondResultPath = tempnam(sys_get_temp_dir(), 'erpv2-stock-race-b-');
        if ($firstResultPath === false || $secondResultPath === false) {
            $this->fail('無法建立 stock_no 並行測試暫存結果檔。');
        }

        $firstPid = pcntl_fork();
        if ($firstPid === -1) {
            $this->fail('pcntl_fork 失敗，無法建立第一個建車行程。');
        }

        if ($firstPid === 0) {
            fclose($sockets[0]);
            $this->runSequenceHoldingCreateInChild($sockets[1], $firstResultPath, $firstPayload, $user->id);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], self::CHILD_HANDSHAKE_TIMEOUT_SECONDS);
        $secondPid = 0;

        try {
            $signal = fread($sockets[0], 1);
            $metadata = stream_get_meta_data($sockets[0]);
            if ($signal !== 'L' || ($metadata['timed_out'] ?? false)) {
                $this->fail('第一個建車行程未在時限內鎖住每日序號列。');
            }

            $secondPid = pcntl_fork();
            if ($secondPid === -1) {
                $this->fail('pcntl_fork 失敗，無法建立第二個建車行程。');
            }

            if ($secondPid === 0) {
                fclose($sockets[0]);
                $this->runCreateRequestInChild($secondResultPath, $secondPayload, $user->id);
            }

            // Give the second process time to contend on the uncommitted sequence row,
            // then let the first transaction commit. Correct locking makes the second
            // request continue with the next number instead of also choosing 0001.
            usleep(250000);
            fwrite($sockets[0], 'C');
            fclose($sockets[0]);

            $firstStatus = $this->waitForChildOrStop($firstPid);
            $firstPid = 0;
            $secondStatus = $this->waitForChildOrStop($secondPid);
            $secondPid = 0;

            $firstResult = $this->readChildResult($firstResultPath);
            $secondResult = $this->readChildResult($secondResultPath);

            $this->assertTrue(
                pcntl_wifexited($firstStatus) && pcntl_wexitstatus($firstStatus) === 0,
                '第一個並行建車請求失敗：'.json_encode($firstResult, JSON_UNESCAPED_UNICODE)
            );
            $this->assertTrue(
                pcntl_wifexited($secondStatus) && pcntl_wexitstatus($secondStatus) === 0,
                '第二個並行建車請求失敗：'.json_encode($secondResult, JSON_UNESCAPED_UNICODE)
            );

            $stockNumbers = [$firstResult['stock_no'] ?? null, $secondResult['stock_no'] ?? null];
            sort($stockNumbers);
            $this->assertSame(['V'.now()->format('Ymd').'0001', 'V'.now()->format('Ymd').'0002'], $stockNumbers);

            DB::purge();
            $this->assertSame(2, Vehicle::query()
                ->whereIn('idempotency_key', [$firstPayload['idempotency_key'], $secondPayload['idempotency_key']])
                ->count());
        } finally {
            if (is_resource($sockets[0])) {
                fclose($sockets[0]);
            }
            if ($firstPid > 0) {
                $this->waitForChildOrStop($firstPid);
            }
            if ($secondPid > 0) {
                $this->waitForChildOrStop($secondPid);
            }
            @unlink($firstResultPath);
            @unlink($secondResultPath);
        }
    }

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $payload
     */
    private function runSequenceHoldingCreateInChild($socket, string $resultPath, array $payload, int $userId): never
    {
        DB::disconnect();
        DB::purge();

        $lockSignalSent = false;
        DB::listen(function (QueryExecuted $query) use (&$lockSignalSent, $socket): void {
            $sql = strtolower($query->sql);
            if ($lockSignalSent || ! str_contains($sql, 'vehicle_stock_sequences') || ! str_contains($sql, 'insert')) {
                return;
            }

            $lockSignalSent = true;
            fwrite($socket, 'L');

            if (fread($socket, 1) !== 'C') {
                throw new RuntimeException('父行程未允許第一個建車交易提交。');
            }
        });

        $this->runCreateRequestInChild($resultPath, $payload, $userId, $socket);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  resource|null  $socket
     */
    private function runCreateRequestInChild(string $resultPath, array $payload, int $userId, $socket = null): never
    {
        DB::disconnect();
        DB::purge();

        try {
            $vehicle = app(VehicleService::class)->createVehicle($payload, $userId);
            file_put_contents($resultPath, json_encode([
                'ok' => true,
                'vehicle_id' => $vehicle->id,
                'stock_no' => $vehicle->stock_no,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            if (is_resource($socket)) {
                fclose($socket);
            }
            exit(0);
        } catch (Throwable $e) {
            file_put_contents($resultPath, json_encode([
                'ok' => false,
                'class' => $e::class,
                'message' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            if (is_resource($socket)) {
                fclose($socket);
            }
            exit(1);
        }
    }

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $payload
     */
    private function runLosingCreateRequestInChild($socket, string $resultPath, array $payload, int $userId): never
    {
        DB::disconnect();
        DB::purge();

        $missSignalSent = false;
        DB::listen(function (QueryExecuted $query) use (&$missSignalSent, $payload, $socket): void {
            if ($missSignalSent || ! self::isIdempotencyMissLookup($query, $payload['idempotency_key'])) {
                return;
            }

            $missSignalSent = true;
            fwrite($socket, 'M');

            $ack = fread($socket, 1);
            if ($ack !== 'C') {
                throw new RuntimeException('父行程未確認 winner 建車已提交。');
            }
        });

        try {
            $vehicle = app(VehicleService::class)->createVehicle($payload, $userId);

            $entryCount = MoneyEntry::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('category', '購車付款')
                ->count();

            if ($entryCount !== 1) {
                throw new RuntimeException("輸家 replay 後購車付款筆數應為 1，實際為 {$entryCount}。");
            }

            file_put_contents($resultPath, json_encode([
                'ok' => true,
                'vehicle_id' => $vehicle->id,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            fclose($socket);
            exit(0);
        } catch (Throwable $e) {
            file_put_contents($resultPath, json_encode([
                'ok' => false,
                'class' => $e::class,
                'message' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            fclose($socket);
            exit(1);
        }
    }

    private static function isIdempotencyMissLookup(QueryExecuted $query, string $idempotencyKey): bool
    {
        $sql = strtolower($query->sql);

        return str_starts_with($sql, 'select')
            && str_contains($sql, 'vehicles')
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
