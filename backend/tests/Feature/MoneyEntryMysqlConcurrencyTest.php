<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\MoneyEntryService;
use App\Services\VehicleService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\RunsMysqlConcurrencyTests;
use Tests\TestCase;
use Throwable;

class MoneyEntryMysqlConcurrencyTest extends TestCase
{
    use RunsMysqlConcurrencyTests;

    public static function races(): array
    {
        return [
            'manual same payload' => ['manual', false],
            'manual different amount' => ['manual', true],
            'shortcut same payload' => ['shortcut', false],
            'shortcut different amount' => ['shortcut', true],
            'reservation key collision across vehicles' => ['reservation', true],
        ];
    }

    #[DataProvider('races')]
    public function test_duplicate_key_recovery_uses_a_fresh_transaction(string $scenario, bool $conflict): void
    {
        $this->skipUnlessRealMysqlConcurrencyTestCanRun();
        $this->assertSafeToFreshMigrateMysqlConcurrencyDatabase();
        $this->artisan('migrate:fresh')->run();

        $actor = User::factory()->admin()->create(['is_active' => true]);
        $account = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create(['status' => $scenario === 'reservation' ? 'listed' : 'preparing']);
        $loserVehicle = $scenario === 'reservation'
            ? Vehicle::factory()->create(['status' => 'listed', 'buyer_name' => null, 'buyer_customer_id' => null])
            : $vehicle;
        $payload = [
            'amount' => 1000, 'cash_account_id' => $account->id,
            'entry_date' => '2026-01-02', 'idempotency_key' => (string) Str::uuid(),
            'direction' => 'expense', 'category' => '其他支出',
            'buyer_name' => '並發測試買方', 'buyer_phone' => null,
            'sold_price' => 100000, 'deposit_amount' => 1000, 'sales_agent_id' => $actor->id,
        ];
        $loserPayload = $payload;
        if ($conflict && $scenario !== 'reservation') {
            $loserPayload['amount']++;
        }

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('此環境不支援 stream_socket_pair。');
        }
        $resultPath = tempnam(sys_get_temp_dir(), 'erpv2-money-race-');
        if ($resultPath === false) {
            $this->fail('無法建立子行程結果檔。');
        }
        // No live PDO connection is inherited by the child.
        DB::disconnect();
        DB::purge();
        $pid = pcntl_fork();
        if ($pid === -1) {
            @unlink($resultPath);
            fclose($sockets[0]);
            fclose($sockets[1]);
            $this->fail('無法建立第二個請求行程。');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            $this->runLoser($sockets[1], $resultPath, $scenario, $loserVehicle->id, $loserPayload, $actor->id);
        }

        fclose($sockets[1]);
        stream_set_timeout($sockets[0], self::CHILD_HANDSHAKE_TIMEOUT_SECONDS);
        try {
            $this->assertSame('M', fread($sockets[0], 1), '子行程必須先建立 idempotency miss 的舊快照。');
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $parentConnection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $winnerId = $this->writeEntry($scenario, $vehicle->id, $payload, $actor->id);
            fwrite($sockets[0], 'C');
            fclose($sockets[0]);
            $status = $this->waitForChildOrStop($pid);
            $pid = 0;
            $result = $this->readChildResult($resultPath);
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, json_encode($result));
            $this->assertTrue($result['ok'] ?? false, json_encode($result));
            $this->assertNotSame($parentConnection, $result['connection_id']);
            $this->assertGreaterThanOrEqual(1, $result['rollbacks']);
            $this->assertGreaterThanOrEqual(1, $result['recovery_reads']);
            if ($conflict) {
                $this->assertArrayHasKey('idempotency_key', $result['errors']);
            } else {
                $this->assertSame([], $result['errors']);
                $this->assertSame($winnerId, $result['entry_id']);
            }
            $this->assertSame(1, MoneyEntry::query()->where('idempotency_key', $payload['idempotency_key'])->count());
            $this->assertSame(1000, MoneyEntry::findOrFail($winnerId)->amount);
            if ($scenario === 'reservation') {
                $this->assertSame('reserved', $vehicle->fresh()->status);
                $this->assertSame('listed', $loserVehicle->fresh()->status);
                $this->assertNull($loserVehicle->fresh()->buyer_name);
                $this->assertNull($loserVehicle->fresh()->buyer_customer_id);
                $this->assertSame(0, MoneyEntry::query()->where('vehicle_id', $loserVehicle->id)->count());
                $this->assertDatabaseCount('customers', 1);
            }
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

    private function writeEntry(string $scenario, int $vehicleId, array $payload, int $actorId): int
    {
        if ($scenario === 'manual') {
            return app(MoneyEntryService::class)->createEntry($payload, User::findOrFail($actorId))->id;
        }
        if ($scenario === 'shortcut') {
            return app(MoneyEntryService::class)->recordVehicleShortcut(Vehicle::findOrFail($vehicleId), 'expense', '維修支出', $payload, $actorId)->id;
        }
        app(VehicleService::class)->reserveVehicle(Vehicle::findOrFail($vehicleId), $payload, $actorId);

        return MoneyEntry::query()->where('idempotency_key', $payload['idempotency_key'])->sole()->id;
    }

    /** @param resource $socket */
    private function runLoser($socket, string $path, string $scenario, int $vehicleId, array $payload, int $actorId): never
    {
        stream_set_timeout($socket, self::CHILD_HANDSHAKE_TIMEOUT_SECONDS);
        $result = ['ok' => false, 'errors' => [], 'entry_id' => null];
        $rollbacks = 0;
        $recoveryReads = 0;
        $signalled = false;
        $exitCode = 0;
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $result['connection_id'] = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            Event::listen(TransactionRolledBack::class, function () use (&$rollbacks): void {
                $rollbacks++;
            });
            DB::listen(function (QueryExecuted $query) use (&$signalled, &$recoveryReads, $payload, $socket): void {
                if (! self::isIdempotencyMissLookup($query, $payload['idempotency_key'])) {
                    return;
                }
                if (str_contains(strtolower($query->sql), 'for update')) {
                    $recoveryReads++;
                }
                if (! $signalled) {
                    $signalled = true;
                    fwrite($socket, 'M');
                    if (fread($socket, 1) !== 'C') {
                        throw new RuntimeException('未收到 winner 已提交的通知。');
                    }
                }
            });
            try {
                $result['entry_id'] = $this->writeEntry($scenario, $vehicleId, $payload, $actorId);
            } catch (ValidationException $exception) {
                $result['errors'] = $exception->errors();
            }
            $result['ok'] = true;
        } catch (Throwable $exception) {
            $result['message'] = $exception->getMessage();
            $exitCode = 1;
        }
        $result['rollbacks'] = $rollbacks;
        $result['recovery_reads'] = $recoveryReads;
        file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        fclose($socket);
        exit($exitCode);
    }
}
