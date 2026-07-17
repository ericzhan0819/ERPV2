<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CommissionPlan;
use App\Models\MoneyEntry;
use App\Models\SalaryPeriod;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CommissionPlanService;
use App\Services\MoneyEntryService;
use App\Services\SalaryEligibilityService;
use App\Services\SalaryPeriodService;
use App\Services\SalaryProfileService;
use App\Services\VehicleService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class SalarySettingsMysqlConcurrencyTest extends TestCase
{
    private const CHILD_HANDSHAKE_TIMEOUT_SECONDS = 10;

    private const CHILD_EXIT_TIMEOUT_SECONDS = 10;

    private const CHILD_STOP_TIMEOUT_SECONDS = 3;

    private const CHILD_WAIT_POLL_MICROSECONDS = 100000;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_salary_profile_duplicate_key_loser_replays_committed_mysql_winner(): void
    {
        $this->prepareDisposableMysqlDatabase();

        $admin = User::factory()->admin()->create();
        $employee = User::factory()->sales()->create();
        $payload = $this->salaryProfilePayload();
        [$parentSocket, $childSocket] = $this->createSocketPair();
        $resultPath = $this->createResultPath('erpv2-salary-profile-race-');
        $pid = pcntl_fork();

        if ($pid === -1) {
            @unlink($resultPath);
            $this->fail('pcntl_fork 失敗，無法建立真正的第二個薪資設定請求。');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            $this->runSalaryProfileLoserInChild($childSocket, $resultPath, $admin->id, $employee->id, $payload);
        }

        fclose($childSocket);
        stream_set_timeout($parentSocket, self::CHILD_HANDSHAKE_TIMEOUT_SECONDS);

        try {
            $this->assertChildSignal($parentSocket, 'M', '輸家請求未在時限內完成 salary profile miss lookup。');

            // 此段說明相鄰程式碼的用途與預期行為。
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $winner = app(SalaryProfileService::class)->upsertProfile($admin, $employee, $payload);

            fwrite($parentSocket, 'C');
            fclose($parentSocket);

            $status = $this->waitForChildOrStop($pid);
            $pid = 0;
            $childResult = $this->readChildResult($resultPath);

            $this->assertTrue(
                pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                '輸家請求應在 duplicate-key rollback 後 replay winner。Child result: '.json_encode($childResult, JSON_UNESCAPED_UNICODE)
            );
            $this->assertSame(true, $childResult['ok'] ?? false);
            $this->assertSame($winner->id, $childResult['profile_id'] ?? null);
            $this->assertSame(1, SalaryProfile::query()->where('user_id', $employee->id)->count());
            $this->assertSame(1, AuditLog::query()->where('subject_type', 'salary_profile')->count());
        } finally {
            $this->cleanupParentResources($parentSocket, $pid, $resultPath);
        }
    }

    public function test_commission_plan_duplicate_name_loser_returns_422_after_committed_mysql_winner(): void
    {
        $this->prepareDisposableMysqlDatabase();

        $admin = User::factory()->admin()->create();
        $payload = $this->commissionPlanPayload();
        [$parentSocket, $childSocket] = $this->createSocketPair();
        $resultPath = $this->createResultPath('erpv2-commission-plan-race-');
        $pid = pcntl_fork();

        if ($pid === -1) {
            @unlink($resultPath);
            $this->fail('pcntl_fork 失敗，無法建立真正的第二個獎金方案請求。');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            $this->runCommissionPlanLoserInChild($childSocket, $resultPath, $admin->id, $payload);
        }

        fclose($childSocket);
        stream_set_timeout($parentSocket, self::CHILD_HANDSHAKE_TIMEOUT_SECONDS);

        try {
            $this->assertChildSignal($parentSocket, 'M', '輸家請求未在時限內到達 commission plan insert barrier。');
            $winner = app(CommissionPlanService::class)->createPlan($admin, $payload);

            fwrite($parentSocket, 'C');
            fclose($parentSocket);

            $status = $this->waitForChildOrStop($pid);
            $pid = 0;
            $childResult = $this->readChildResult($resultPath);

            $this->assertTrue(
                pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                '輸家請求應在 duplicate-key rollback 後收到 422。Child result: '.json_encode($childResult, JSON_UNESCAPED_UNICODE)
            );
            $this->assertSame(true, $childResult['ok'] ?? false);
            $this->assertSame('validation', $childResult['result'] ?? null);
            $this->assertSame(['name'], $childResult['error_fields'] ?? null);
            $this->assertSame($winner->id, CommissionPlan::query()->where('name', $payload['name'])->value('id'));
            $this->assertSame(3, $winner->tiers()->count());
            $this->assertSame(1, AuditLog::query()->where('subject_type', 'commission_plan')->count());
        } finally {
            $this->cleanupParentResources($parentSocket, $pid, $resultPath);
        }
    }

    public function test_vehicle_money_approval_waits_for_salary_confirmation_candidate_lock(): void
    {
        $this->prepareDisposableMysqlDatabase();

        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $agent = User::factory()->sales()->create();
        $vehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-06-15 12:00:00',
            'purchase_price' => 60000,
            'sold_price' => 100000,
            'purchase_agent_id' => $agent->id,
            'sales_agent_id' => $agent->id,
        ]);
        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'direction' => 'income',
            'category' => '訂金收入',
            'amount' => 100000,
            'approval_status' => MoneyEntry::APPROVAL_APPROVED,
            'source_type' => MoneyEntry::SOURCE_MANUAL,
        ]);
        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'direction' => 'expense',
            'category' => '購車付款',
            'amount' => 60000,
            'approval_status' => MoneyEntry::APPROVAL_APPROVED,
            'source_type' => MoneyEntry::SOURCE_MANUAL,
        ]);
        $pendingRepair = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'direction' => 'expense',
            'category' => '維修支出',
            'amount' => 50000,
            'approval_status' => MoneyEntry::APPROVAL_PENDING,
            'source_type' => MoneyEntry::SOURCE_MANUAL,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);

        [$parentSocket, $childSocket] = $this->createSocketPair();
        $resultPath = $this->createResultPath('erpv2-salary-eligibility-lock-');
        $pid = pcntl_fork();

        if ($pid === -1) {
            @unlink($resultPath);
            $this->fail('pcntl_fork 失敗，無法建立真正的第二個收支核准請求。');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            $this->runVehicleMoneyApprovalInChild($childSocket, $resultPath, $pendingRepair->id, $admin->id);
        }

        fclose($childSocket);
        stream_set_timeout($parentSocket, self::CHILD_HANDSHAKE_TIMEOUT_SECONDS);
        $this->assertChildSignal($parentSocket, 'R', '收支核准 child 未完成獨立資料庫連線初始化。');
        DB::purge();
        DB::beginTransaction();

        try {
            try {
                app(SalaryEligibilityService::class)->assertPeriodEligible('2026-06');
                $this->fail('待審維修支出必須阻擋薪資確認。');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('salary_eligibility', $exception->errors());
            }

            fwrite($parentSocket, 'G');
            $this->assertChildSignal($parentSocket, 'S', '收支核准 child 未開始執行。');

            stream_set_blocking($parentSocket, false);
            usleep(300000);
            $this->assertSame('', fread($parentSocket, 1), '車輛列鎖釋放前，維修支出核准不得先完成。');

            DB::commit();
            stream_set_blocking($parentSocket, true);
            stream_set_timeout($parentSocket, self::CHILD_HANDSHAKE_TIMEOUT_SECONDS);
            $this->assertChildSignal($parentSocket, 'D', '車輛列鎖釋放後，收支核准未在時限內完成。');
            fclose($parentSocket);

            $status = $this->waitForChildOrStop($pid);
            $pid = 0;
            $childResult = $this->readChildResult($resultPath);

            $this->assertTrue(
                pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                '收支核准 child 應在確認交易釋放車輛鎖後完成。Child result: '.json_encode($childResult, JSON_UNESCAPED_UNICODE),
            );
            $this->assertSame(true, $childResult['ok'] ?? false);
            $this->assertSame(MoneyEntry::APPROVAL_APPROVED, MoneyEntry::query()->findOrFail($pendingRepair->id)->approval_status);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanupParentResources($parentSocket, $pid, $resultPath);
        }
    }

    public function test_close_sale_waits_for_salary_period_lock_before_locking_vehicle(): void
    {
        $this->prepareDisposableMysqlDatabase();

        $admin = User::factory()->admin()->create();
        $agent = User::factory()->sales()->create();
        $plan = CommissionPlan::query()->create([
            'name' => '成交與薪資鎖序測試方案',
            'effective_from' => '2026-01-01',
            'company_reserve_bps' => 4000,
            'purchase_bonus_bps' => 2000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $plan->tiers()->create([
            'min_sales_count' => 1,
            'sales_bonus_bps' => 2000,
            'sort_order' => 1,
        ]);
        $period = SalaryPeriod::query()->create([
            'period_month' => '2026-06-01',
            'commission_plan_id' => $plan->id,
            'status' => SalaryPeriod::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
        $vehicle = Vehicle::factory()->create([
            'status' => 'reserved',
            'sold_price' => 100000,
            'buyer_name' => '鎖序測試買方',
            'purchase_agent_id' => $agent->id,
            'sales_agent_id' => $agent->id,
        ]);
        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'direction' => 'income',
            'category' => '尾款收入',
            'amount' => 100000,
            'approval_status' => MoneyEntry::APPROVAL_APPROVED,
            'source_type' => MoneyEntry::SOURCE_MANUAL,
        ]);

        [$parentSocket, $childSocket] = $this->createSocketPair();
        $resultPath = $this->createResultPath('erpv2-close-sale-period-lock-');
        $pid = pcntl_fork();

        if ($pid === -1) {
            @unlink($resultPath);
            $this->fail('pcntl_fork 失敗，無法建立真正的第二個成交結案請求。');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            $this->runCloseSaleInChild($childSocket, $resultPath, $vehicle->id, $admin->id);
        }

        fclose($childSocket);
        stream_set_timeout($parentSocket, self::CHILD_HANDSHAKE_TIMEOUT_SECONDS);
        $this->assertChildSignal($parentSocket, 'R', '成交結案 child 未完成獨立資料庫連線初始化。');
        DB::purge();
        DB::beginTransaction();

        try {
            $lockedPeriod = SalaryPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            fwrite($parentSocket, 'G');
            $this->assertChildSignal($parentSocket, 'S', '成交結案 child 未開始執行。');

            // 讓 child 有時間進入 closeSale()。正確實作會先等待 period 鎖，尚未持有 vehicle；
            // 若順序退化成 vehicle → period，這裡取得 vehicle 鎖會形成反向等待並觸發 deadlock。
            usleep(300000);
            $lockedVehicle = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $this->assertSame('reserved', $lockedVehicle->status);

            $lockedPeriod->status = SalaryPeriod::STATUS_CONFIRMED;
            $lockedPeriod->confirmed_by = $admin->id;
            $lockedPeriod->confirmed_at = now();
            $lockedPeriod->save();
            DB::commit();

            $this->assertChildSignal($parentSocket, 'D', 'period 鎖釋放後，成交結案 child 未在時限內完成。');
            fclose($parentSocket);
            $status = $this->waitForChildOrStop($pid);
            $pid = 0;
            $childResult = $this->readChildResult($resultPath);

            $this->assertTrue(
                pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                '成交結案應等待 period 鎖，醒來後因月份已確認而回 422。Child result: '.json_encode($childResult, JSON_UNESCAPED_UNICODE),
            );
            $this->assertSame(true, $childResult['ok'] ?? false);
            $this->assertSame('validation', $childResult['result'] ?? null);
            $this->assertSame(['sold_at'], $childResult['error_fields'] ?? null);
            $this->assertSame('reserved', Vehicle::query()->findOrFail($vehicle->id)->status);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanupParentResources($parentSocket, $pid, $resultPath);
        }
    }

    public function test_salary_payment_duplicate_key_loser_rolls_back_batch_and_reads_committed_winner(): void
    {
        $this->prepareDisposableMysqlDatabase();

        $admin = User::factory()->admin()->create(['is_active' => true]);
        $employee = User::factory()->sales()->create(['is_active' => true]);
        SalaryProfile::query()->create([
            'user_id' => $employee->id,
            'base_salary' => 30000,
            'fixed_allowance' => 0,
            'labor_insurance_deduction' => 0,
            'health_insurance_deduction' => 0,
            'commission_enabled' => true,
            'is_active' => true,
        ]);
        $plan = CommissionPlan::query()->create([
            'name' => '發薪並發測試方案',
            'effective_from' => '2026-01-01',
            'company_reserve_bps' => 4000,
            'purchase_bonus_bps' => 2000,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $plan->tiers()->create(['min_sales_count' => 1, 'sales_bonus_bps' => 2000, 'sort_order' => 1]);
        $service = app(SalaryPeriodService::class);
        $winnerPeriod = $service->confirm($admin, $service->createDraft($admin, '2026-06'));
        $loserPeriod = $service->confirm($admin, $service->createDraft($admin, '2026-07'));
        $winnerAccount = CashAccount::factory()->create(['is_active' => true]);
        $loserAccount = CashAccount::factory()->create(['is_active' => true]);
        $idempotencyKey = 'mysql-salary-payment-race';
        [$parentSocket, $childSocket] = $this->createSocketPair();
        $resultPath = $this->createResultPath('erpv2-salary-payment-race-');
        $pid = pcntl_fork();

        if ($pid === -1) {
            @unlink($resultPath);
            $this->fail('pcntl_fork 失敗，無法建立真正的第二個發薪請求。');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            $this->runSalaryPaymentLoserInChild(
                $childSocket,
                $resultPath,
                $admin->id,
                $loserPeriod->id,
                $loserAccount->id,
                $idempotencyKey,
            );
        }

        fclose($childSocket);
        stream_set_timeout($parentSocket, self::CHILD_HANDSHAKE_TIMEOUT_SECONDS);

        try {
            $this->assertChildSignal($parentSocket, 'M', '輸家發薪未在 salary period update 前到達同步屏障。');
            DB::purge();
            $paid = app(SalaryPeriodService::class)->pay(
                User::query()->findOrFail($admin->id),
                SalaryPeriod::query()->findOrFail($winnerPeriod->id),
                [
                    'cash_account_id' => $winnerAccount->id,
                    'payment_date' => '2026-06-30',
                    'idempotency_key' => $idempotencyKey,
                ],
            );
            $this->assertSame(SalaryPeriod::STATUS_PAID, $paid->status);

            fwrite($parentSocket, 'C');
            fclose($parentSocket);
            $status = $this->waitForChildOrStop($pid);
            $pid = 0;
            $childResult = $this->readChildResult($resultPath);

            $this->assertTrue(
                pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
                '輸家應完整 rollback 後以新交易讀到另一月份的 winner。Child result: '.json_encode($childResult, JSON_UNESCAPED_UNICODE),
            );
            $this->assertSame(true, $childResult['ok'] ?? false);
            $this->assertSame('validation', $childResult['result'] ?? null);
            $this->assertSame(['idempotency_key'], $childResult['error_fields'] ?? null);
            $this->assertSame(SalaryPeriod::STATUS_CONFIRMED, SalaryPeriod::query()->findOrFail($loserPeriod->id)->status);
            $this->assertSame(1, MoneyEntry::query()->where('source_type', MoneyEntry::SOURCE_SALARY_SETTLEMENT)->count());
            $this->assertSame(0, SalaryPeriod::query()->findOrFail($loserPeriod->id)->settlements()->whereNotNull('money_entry_id')->count());
        } finally {
            $this->cleanupParentResources($parentSocket, $pid, $resultPath);
        }
    }

    public function test_paid_salary_history_trigger_migration_is_safe_to_rerun_on_mysql(): void
    {
        $this->prepareDisposableMysqlDatabase();
        $migrationPath = database_path('migrations/2026_07_13_000012_protect_paid_salary_history.php');

        (require $migrationPath)->up();
        (require $migrationPath)->up();

        $triggerCount = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TRIGGER_NAME', [
                'paid_salary_period_update', 'paid_salary_period_delete',
                'paid_salary_settlement_insert', 'paid_salary_settlement_update', 'paid_salary_settlement_delete',
                'paid_salary_item_insert', 'paid_salary_item_update', 'paid_salary_item_delete',
            ])
            ->count();
        $this->assertSame(8, $triggerCount);
    }

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $payload
     */
    private function runSalaryProfileLoserInChild($socket, string $resultPath, int $adminId, int $employeeId, array $payload): never
    {
        DB::disconnect();
        DB::purge();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        $missSignalSent = false;
        DB::listen(function (QueryExecuted $query) use (&$missSignalSent, $employeeId, $socket): void {
            $sql = strtolower($query->sql);
            if ($missSignalSent
                || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'salary_profiles')
                || ! str_contains($sql, 'user_id')
                || ! in_array($employeeId, $query->bindings, true)) {
                return;
            }

            $missSignalSent = true;
            fwrite($socket, 'M');

            if (fread($socket, 1) !== 'C') {
                throw new RuntimeException('父行程未確認 salary profile winner 已提交。');
            }
        });

        try {
            $profile = app(SalaryProfileService::class)->upsertProfile(
                User::query()->findOrFail($adminId),
                User::query()->findOrFail($employeeId),
                $payload,
            );

            $this->writeChildResult($resultPath, [
                'ok' => true,
                'profile_id' => $profile->id,
            ]);
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            $this->writeChildException($resultPath, $exception);
            fclose($socket);
            exit(1);
        }
    }

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $payload
     */
    private function runCommissionPlanLoserInChild($socket, string $resultPath, int $adminId, array $payload): never
    {
        DB::disconnect();
        DB::purge();

        $barrierReached = false;
        CommissionPlan::creating(function () use (&$barrierReached, $socket): void {
            if ($barrierReached) {
                return;
            }

            $barrierReached = true;
            fwrite($socket, 'M');

            if (fread($socket, 1) !== 'C') {
                throw new RuntimeException('父行程未確認 commission plan winner 已提交。');
            }
        });

        try {
            app(CommissionPlanService::class)->createPlan(User::query()->findOrFail($adminId), $payload);
            $this->writeChildResult($resultPath, [
                'ok' => false,
                'result' => 'unexpected_success',
            ]);
            fclose($socket);
            exit(1);
        } catch (ValidationException $exception) {
            $this->writeChildResult($resultPath, [
                'ok' => true,
                'result' => 'validation',
                'error_fields' => array_keys($exception->errors()),
            ]);
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            $this->writeChildException($resultPath, $exception);
            fclose($socket);
            exit(1);
        }
    }

    /** @param resource $socket */
    private function runVehicleMoneyApprovalInChild(
        $socket,
        string $resultPath,
        int $moneyEntryId,
        int $adminId,
    ): never {
        DB::disconnect();
        DB::purge();
        fwrite($socket, 'R');

        if (fread($socket, 1) !== 'G') {
            $this->writeChildResult($resultPath, ['ok' => false, 'message' => '父行程未開始鎖定測試']);
            fclose($socket);
            exit(1);
        }

        fwrite($socket, 'S');

        try {
            $entry = app(MoneyEntryService::class)->approve(
                MoneyEntry::query()->findOrFail($moneyEntryId),
                $adminId,
            );
            $this->writeChildResult($resultPath, [
                'ok' => true,
                'approval_status' => $entry->approval_status,
            ]);
            fwrite($socket, 'D');
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            $this->writeChildException($resultPath, $exception);
            fwrite($socket, 'D');
            fclose($socket);
            exit(1);
        }
    }

    /** @param resource $socket */
    private function runCloseSaleInChild(
        $socket,
        string $resultPath,
        int $vehicleId,
        int $adminId,
    ): never {
        DB::disconnect();
        DB::purge();
        fwrite($socket, 'R');

        if (fread($socket, 1) !== 'G') {
            $this->writeChildResult($resultPath, ['ok' => false, 'message' => '父行程未開始鎖定測試']);
            fclose($socket);
            exit(1);
        }

        fwrite($socket, 'S');

        try {
            app(VehicleService::class)->closeSale(
                Vehicle::query()->findOrFail($vehicleId),
                ['sold_at' => '2026-06-15'],
                $adminId,
            );
            $this->writeChildResult($resultPath, ['ok' => false, 'result' => 'unexpected_success']);
            fwrite($socket, 'D');
            fclose($socket);
            exit(1);
        } catch (ValidationException $exception) {
            $this->writeChildResult($resultPath, [
                'ok' => true,
                'result' => 'validation',
                'error_fields' => array_keys($exception->errors()),
            ]);
            fwrite($socket, 'D');
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            $this->writeChildException($resultPath, $exception);
            fwrite($socket, 'D');
            fclose($socket);
            exit(1);
        }
    }

    /** @param resource $socket */
    private function runSalaryPaymentLoserInChild(
        $socket,
        string $resultPath,
        int $adminId,
        int $periodId,
        int $accountId,
        string $idempotencyKey,
    ): never {
        DB::disconnect();
        DB::purge();
        $barrierReached = false;
        SalaryPeriod::saving(function (SalaryPeriod $period) use (&$barrierReached, $idempotencyKey, $socket): void {
            if ($barrierReached
                || $period->status !== SalaryPeriod::STATUS_PAID
                || $period->idempotency_key !== $idempotencyKey) {
                return;
            }

            $barrierReached = true;
            fwrite($socket, 'M');
            if (fread($socket, 1) !== 'C') {
                throw new RuntimeException('父行程未確認發薪 winner 已提交。');
            }
        });

        try {
            app(SalaryPeriodService::class)->pay(
                User::query()->findOrFail($adminId),
                SalaryPeriod::query()->findOrFail($periodId),
                [
                    'cash_account_id' => $accountId,
                    'payment_date' => '2026-07-14',
                    'idempotency_key' => $idempotencyKey,
                ],
            );
            $this->writeChildResult($resultPath, ['ok' => false, 'result' => 'unexpected_success']);
            fclose($socket);
            exit(1);
        } catch (ValidationException $exception) {
            $this->writeChildResult($resultPath, [
                'ok' => true,
                'result' => 'validation',
                'error_fields' => array_keys($exception->errors()),
            ]);
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            $this->writeChildException($resultPath, $exception);
            fclose($socket);
            exit(1);
        }
    }

    private function prepareDisposableMysqlDatabase(): void
    {
        $this->skipUnlessRealMysqlConcurrencyTestCanRun();
        $this->assertSafeToFreshMigrateMysqlConcurrencyDatabase();
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Taipei'));
        $this->artisan('migrate:fresh', ['--seed' => true])->run();
    }

    private function skipUnlessRealMysqlConcurrencyTestCanRun(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('此測試需要 MySQL/MariaDB；SQLite 無法驗證真正跨連線競態。');
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
        $this->assertNotSame('', $allowedConnection, '拒絕執行 migrate:fresh：必須設定專用測試連線 allowlist。');
        $this->assertSame($allowedConnection, $connectionName, '拒絕執行 migrate:fresh：目前 DB connection 不在 allowlist。');
        $this->assertNotSame('', $allowedDatabase, '拒絕執行 migrate:fresh：必須設定可拋棄測試資料庫 allowlist。');
        $this->assertSame($allowedDatabase, $databaseName, '拒絕執行 migrate:fresh：目前 DB database 不在 allowlist。');
        $this->assertTrue(
            self::isClearlyDisposableTestDatabaseName($databaseName),
            "拒絕執行 migrate:fresh：資料庫名稱 [{$databaseName}] 必須明確為 test/testing/phpunit/ci，且不得包含 prod/production/live/staging/dev/local。"
        );
    }

    private static function isClearlyDisposableTestDatabaseName(string $databaseName): bool
    {
        $normalized = strtolower($databaseName);

        return preg_match('/(^|[_-])(test|testing|phpunit|ci)([_-]|$)/', $normalized) === 1
            && preg_match('/prod|production|live|staging|stage|dev|development|local/', $normalized) !== 1;
    }

    /** @return array{0: resource, 1: resource} */
    private function createSocketPair(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('此環境不支援 stream_socket_pair，無法建立父子行程同步屏障。');
        }

        return $sockets;
    }

    /** @param resource $socket */
    private function assertChildSignal($socket, string $expected, string $message): void
    {
        $signal = fread($socket, 1);
        $metadata = stream_get_meta_data($socket);

        if ($signal !== $expected || ($metadata['timed_out'] ?? false)) {
            $this->fail($message);
        }
    }

    private function createResultPath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            $this->fail('無法建立 MySQL 並行測試暫存結果檔。');
        }

        return $path;
    }

    /** @param resource $socket */
    private function cleanupParentResources($socket, int $pid, string $resultPath): void
    {
        if (is_resource($socket)) {
            fclose($socket);
        }

        if ($pid > 0) {
            $this->waitForChildOrStop($pid);
        }

        @unlink($resultPath);
    }

    private function waitForChildOrStop(int $pid): int
    {
        $status = $this->waitForChild($pid, self::CHILD_EXIT_TIMEOUT_SECONDS);
        if ($status !== null) {
            return $status;
        }

        posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
        $status = $this->waitForChild($pid, self::CHILD_STOP_TIMEOUT_SECONDS);
        if ($status !== null) {
            return $status;
        }

        posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);
        $status = $this->waitForChild($pid, self::CHILD_STOP_TIMEOUT_SECONDS);
        if ($status !== null) {
            return $status;
        }

        throw new RuntimeException("Child process {$pid} did not exit after bounded waits.");
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

    /** @return array<string, mixed> */
    private function readChildResult(string $resultPath): array
    {
        $contents = file_get_contents($resultPath);
        if ($contents === false || $contents === '') {
            return ['ok' => false, 'message' => 'child 未寫入結果'];
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $result */
    private function writeChildResult(string $resultPath, array $result): void
    {
        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function writeChildException(string $resultPath, Throwable $exception): void
    {
        $this->writeChildResult($resultPath, [
            'ok' => false,
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    /** @return array<string, mixed> */
    private function salaryProfilePayload(): array
    {
        return [
            'base_salary' => 35000,
            'fixed_allowance' => 1200,
            'labor_insurance_deduction' => 800,
            'health_insurance_deduction' => 600,
            'commission_enabled' => true,
            'is_active' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function commissionPlanPayload(): array
    {
        return [
            'name' => '真並發測試方案',
            'effective_from' => '2027-01-01',
            'company_reserve_bps' => 4000,
            'purchase_bonus_bps' => 2000,
            'is_active' => true,
            'tiers' => [
                ['min_sales_count' => 1, 'sales_bonus_bps' => 2000],
                ['min_sales_count' => 3, 'sales_bonus_bps' => 3000],
                ['min_sales_count' => 5, 'sales_bonus_bps' => 5000],
            ],
        ];
    }
}
