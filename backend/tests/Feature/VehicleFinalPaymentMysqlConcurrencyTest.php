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

class VehicleFinalPaymentMysqlConcurrencyTest extends TestCase
{
    private const CHILD_HANDSHAKE_TIMEOUT_SECONDS = 10;

    private const CHILD_EXIT_TIMEOUT_SECONDS = 10;

    private const CHILD_STOP_TIMEOUT_SECONDS = 3;

    private const CHILD_WAIT_POLL_MICROSECONDS = 100000;

    private const SOCKET_TIMEOUT_EXERCISE_MICROSECONDS = 100000;

    public function test_final_payment_duplicate_key_loser_replays_after_committed_mysql_winner(): void
    {
        $this->skipUnlessRealMysqlConcurrencyTestCanRun();

        $this->assertSafeToFreshMigrateMysqlConcurrencyDatabase();

        $this->artisan('migrate:fresh')->run();

        // 本案例驗證「已核准」訂金＋尾款的 duplicate-key replay；使用 admin 才符合
        // 現行 approval 契約，manager／sales 建立的收款會保持 pending，不應拿來斷言足額警告為 null。
        $user = User::factory()->admin()->create(['is_active' => true]);
        $cashAccount = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = $this->createReservedVehicleWithDeposit($user, $cashAccount, 480000, 100000);
        $payload = [
            'amount' => 380000,
            'cash_account_id' => $cashAccount->id,
            'entry_date' => '2026-01-02',
            'idempotency_key' => (string) Str::uuid(),
        ];

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('此環境不支援 stream_socket_pair，無法建立父子行程同步屏障。');
        }

        $resultPath = tempnam(sys_get_temp_dir(), 'erpv2-final-payment-race-');
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
            $this->runLosingFinalPaymentRequestInChild(
                $sockets[1],
                $resultPath,
                $vehicle->id,
                $payload,
                $user->id
            );
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

            $winnerResult = app(VehicleService::class)->recordFinalPayment(
                $vehicle->fresh(),
                $payload,
                $user->id
            );
            $this->assertSame($vehicle->id, $winnerResult['vehicle']->id);
            $this->assertNull($winnerResult['warning']);

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

            $this->assertSame(1, MoneyEntry::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('category', '尾款收入')
                ->where('idempotency_key', $payload['idempotency_key'])
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

    public function test_child_cleanup_after_socket_timeout_is_bounded(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('此測試需要 pcntl 與 posix extension 才能驗證 child cleanup timeout path。');
        }

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('此環境不支援 stream_socket_pair，無法建立父子行程同步屏障。');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork 失敗，無法驗證 timeout cleanup。');
        }

        if ($pid === 0) {
            fclose($sockets[0]);
            usleep(self::SOCKET_TIMEOUT_EXERCISE_MICROSECONDS * 3);
            @fwrite($sockets[1], 'M');
            @fread($sockets[1], 1);
            sleep(30);
            fclose($sockets[1]);
            exit(99);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 0, self::SOCKET_TIMEOUT_EXERCISE_MICROSECONDS);

        try {
            fread($sockets[0], 1);
            $metadata = stream_get_meta_data($sockets[0]);

            $this->assertTrue($metadata['timed_out'] ?? false);

            fclose($sockets[0]);
            $status = $this->waitForChildOrStop($pid, 1, 1);
            $pid = 0;

            $this->assertTrue(
                pcntl_wifsignaled($status) || (pcntl_wifexited($status) && pcntl_wexitstatus($status) !== 0),
                'timeout cleanup 應以 bounded wait 結束或停止 child。Child status: '.$this->formatChildStatus($status)
            );
        } finally {
            if (is_resource($sockets[0])) {
                fclose($sockets[0]);
            }

            if ($pid > 0) {
                $this->waitForChildOrStop($pid, 1, 1);
            }
        }
    }

    /**
     * @param  resource  $socket
     * @param  array{amount: int, cash_account_id: int, entry_date: string, idempotency_key: string}  $payload
     */
    private function runLosingFinalPaymentRequestInChild($socket, string $resultPath, int $vehicleId, array $payload, int $userId): never
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
                throw new RuntimeException('父行程未確認 winner final payment 已提交。');
            }
        });

        try {
            $result = app(VehicleService::class)->recordFinalPayment(
                Vehicle::query()->findOrFail($vehicleId),
                $payload,
                $userId
            );

            if ($result['vehicle']->id !== $vehicleId) {
                throw new RuntimeException('輸家 replay 回傳了錯誤車輛。');
            }

            $entryCount = MoneyEntry::query()
                ->where('vehicle_id', $vehicleId)
                ->where('category', '尾款收入')
                ->where('idempotency_key', $payload['idempotency_key'])
                ->count();

            if ($entryCount !== 1) {
                throw new RuntimeException("輸家 replay 後尾款筆數應為 1，實際為 {$entryCount}。");
            }

            file_put_contents($resultPath, json_encode(['ok' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
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

    private function createReservedVehicleWithDeposit(User $user, CashAccount $cashAccount, int $soldPrice, int $depositAmount): Vehicle
    {
        $vehicle = Vehicle::factory()->create(['status' => 'preparing']);
        $service = app(VehicleService::class);

        $listedVehicle = $service->listVehicle($vehicle, [
            'asking_price' => $soldPrice,
            'floor_price' => $soldPrice - 50000,
            'listing_date' => '2026-01-01',
        ], $user->id);

        return $service->reserveVehicle($listedVehicle, [
            'buyer_name' => '王小明',
            'sold_price' => $soldPrice,
            'deposit_amount' => $depositAmount,
            'cash_account_id' => $cashAccount->id,
            'entry_date' => '2026-01-01',
            'idempotency_key' => (string) Str::uuid(),
            'sales_agent_id' => $user->id,
        ], $user->id)->refresh();
    }
}
