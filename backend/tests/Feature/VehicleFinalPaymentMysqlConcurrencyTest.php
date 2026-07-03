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
    public function test_final_payment_duplicate_key_loser_replays_after_committed_mysql_winner(): void
    {
        $this->skipUnlessRealMysqlConcurrencyTestCanRun();

        $this->artisan('migrate:fresh', ['--force' => true])->run();

        $user = User::factory()->create(['is_active' => true]);
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
        stream_set_timeout($sockets[0], 10);

        try {
            $signal = fread($sockets[0], 1);
            $metadata = stream_get_meta_data($sockets[0]);
            if ($signal !== 'M' || $metadata['timed_out']) {
                pcntl_waitpid($pid, $status);
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

            pcntl_waitpid($pid, $status);
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
            @unlink($resultPath);
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

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('此測試需要 pcntl extension 才能建立真正的兩個 PHP 請求行程。');
        }
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
        ], $user->id)->refresh();
    }
}
