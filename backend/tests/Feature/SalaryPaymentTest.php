<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CommissionPlan;
use App\Models\MoneyEntry;
use App\Models\SalaryPeriod;
use App\Models\SalaryProfile;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\MoneyEntryService;
use App\Services\SalaryPeriodService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class SalaryPaymentTest extends TestCase
{
    use RefreshDatabase;

    private SalaryPeriodService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-01 10:00:00');
        $this->service = app(SalaryPeriodService::class);
        $this->admin = User::factory()->admin()->create(['is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pay_creates_one_approved_salary_entry_per_positive_settlement_and_updates_formal_totals(): void
    {
        $employee = $this->employeeWithProfile('王小明', 30000, 2000, 900, 600);
        $zeroPayEmployee = $this->employeeWithProfile('零元員工', 1500, 0, 900, 600);
        $period = $this->confirmedPeriod('2026-06');
        $account = CashAccount::factory()->create([
            'type' => 'bank',
            'opening_balance' => 100000,
            'is_active' => true,
        ]);
        $payload = $this->payload($account, 'salary-pay-june');
        $payload['payment_date'] = '2026-07-01';

        $paid = $this->service->pay($this->admin, $period, $payload);

        $this->assertSame(SalaryPeriod::STATUS_PAID, $paid->status);
        $this->assertSame($account->id, $paid->cash_account_id);
        $this->assertSame('2026-07-01', $paid->payment_date->toDateString());
        $this->assertSame($this->admin->id, $paid->paid_by);
        $this->assertNotNull($paid->paid_at);

        $entry = MoneyEntry::query()->where('source_type', MoneyEntry::SOURCE_SALARY_SETTLEMENT)->sole();
        $this->assertSame(30500, $entry->amount);
        $this->assertSame('expense', $entry->direction);
        $this->assertSame('薪資 / 佣金', $entry->category);
        $this->assertNull($entry->vehicle_id);
        $this->assertSame(MoneyEntry::APPROVAL_APPROVED, $entry->approval_status);
        $this->assertSame($employee->name, $entry->counterparty_name);
        $this->assertSame('2026-06 薪資 / 佣金', $entry->description);
        $this->assertSame($this->admin->id, $entry->approved_by);
        $this->assertNotNull($entry->approved_at);

        $this->assertSame($entry->id, SalarySettlement::query()->where('user_id', $employee->id)->value('money_entry_id'));
        $this->assertNull(SalarySettlement::query()->where('user_id', $zeroPayEmployee->id)->value('money_entry_id'));
        $this->assertSame(69500, app(MoneyEntryService::class)->balanceForAccount($account));
        $this->assertSame(30500, app(DashboardService::class)->summary()['monthly_expense']);

        $audit = AuditLog::query()->where('subject_type', 'salary_period')->whereJsonContains('after_values->operation', 'pay')->sole();
        $this->assertSame(SalaryPeriod::STATUS_PAID, $audit->after_values['status']);
        $this->assertArrayNotHasKey('amount', $audit->after_values);
    }

    public function test_pay_is_admin_confirmed_only_and_requires_an_active_account(): void
    {
        $this->employeeWithProfile('員工', 30000);
        $draft = $this->draftPeriod('2026-06');
        $account = CashAccount::factory()->create(['is_active' => true]);

        foreach ([
            User::factory()->manager()->create(['is_active' => true]),
            User::factory()->sales()->create(['is_active' => true]),
            User::factory()->admin()->create(['is_active' => false]),
        ] as $actor) {
            try {
                $this->service->pay($actor, $draft, $this->payload($account, 'unauthorized-'.$actor->id));
                $this->fail('非啟用中 admin 不得發薪');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $this->expectValidation(fn () => $this->service->pay($this->admin, $draft, $this->payload($account, 'draft-pay')), 'status');
        $confirmed = $this->service->confirm($this->admin, $draft);
        $account->update(['is_active' => false]);
        $this->expectValidation(fn () => $this->service->pay($this->admin, $confirmed, $this->payload($account, 'inactive-account')), 'cash_account_id');

        $this->assertSame(SalaryPeriod::STATUS_CONFIRMED, $confirmed->fresh()->status);
        $this->assertSame(0, MoneyEntry::query()->where('source_type', MoneyEntry::SOURCE_SALARY_SETTLEMENT)->count());
    }

    public function test_payment_replays_same_payload_and_rejects_conflicts_without_duplicate_entries(): void
    {
        $this->employeeWithProfile('員工', 30000);
        $period = $this->confirmedPeriod('2026-06');
        $account = CashAccount::factory()->create(['is_active' => true]);
        $otherAccount = CashAccount::factory()->create(['is_active' => true]);
        $payload = $this->payload($account, 'stable-key');

        $first = $this->service->pay($this->admin, $period, $payload);
        $second = $this->service->pay($this->admin, $period, $payload);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MoneyEntry::query()->where('source_type', MoneyEntry::SOURCE_SALARY_SETTLEMENT)->count());
        $this->assertSame(1, AuditLog::query()->where('subject_type', 'salary_period')->whereJsonContains('after_values->operation', 'pay')->count());

        $this->expectValidation(fn () => $this->service->pay($this->admin, $period, $this->payload($otherAccount, 'stable-key')), 'idempotency_key');
        $this->expectValidation(fn () => $this->service->pay($this->admin, $period, $this->payload($account, 'different-key')), 'status');
        $this->assertSame(1, MoneyEntry::query()->where('source_type', MoneyEntry::SOURCE_SALARY_SETTLEMENT)->count());
    }

    public function test_any_entry_failure_rolls_back_the_entire_payment_batch(): void
    {
        $this->employeeWithProfile('甲員工', 30000);
        $this->employeeWithProfile('乙員工', 32000);
        $period = $this->confirmedPeriod('2026-06');
        $account = CashAccount::factory()->create(['is_active' => true]);
        $listener = function (MoneyEntry $entry): void {
            if ($entry->source_type === MoneyEntry::SOURCE_SALARY_SETTLEMENT) {
                throw new RuntimeException('模擬批次中途失敗');
            }
        };
        Event::listen('eloquent.created: '.MoneyEntry::class, $listener);

        try {
            $this->service->pay($this->admin, $period, $this->payload($account, 'rollback-key'));
            $this->fail('批次中途失敗必須拋出例外');
        } catch (RuntimeException $exception) {
            $this->assertSame('模擬批次中途失敗', $exception->getMessage());
        } finally {
            Event::forget('eloquent.created: '.MoneyEntry::class);
        }

        $this->assertSame(SalaryPeriod::STATUS_CONFIRMED, $period->fresh()->status);
        $this->assertSame(0, MoneyEntry::query()->where('source_type', MoneyEntry::SOURCE_SALARY_SETTLEMENT)->count());
        $this->assertSame(0, SalarySettlement::query()->whereNotNull('money_entry_id')->count());
    }

    public function test_paid_history_cannot_be_changed_or_deleted_even_by_direct_database_writes(): void
    {
        $this->employeeWithProfile('員工', 30000);
        $period = $this->confirmedPeriod('2026-06');
        $account = CashAccount::factory()->create(['is_active' => true]);
        $paid = $this->service->pay($this->admin, $period, $this->payload($account, 'immutable-key'));
        $settlement = $paid->settlements->first();
        $item = $settlement->items->first();
        $draft = $this->draftPeriod('2026-07');
        $otherEmployee = User::factory()->sales()->create(['is_active' => true]);
        $movableSettlement = SalarySettlement::query()->create([
            'salary_period_id' => $draft->id,
            'user_id' => $otherEmployee->id,
            'net_pay' => 50000,
        ]);
        $movableItem = SalarySettlementItem::query()->create([
            'salary_settlement_id' => $movableSettlement->id,
            'type' => SalarySettlementItem::TYPE_MANUAL_ADDITION,
            'amount' => 50000,
            'description' => '不得搬入已發薪月份',
            'created_by' => $this->admin->id,
        ]);

        foreach ([
            fn () => SalaryPeriod::query()->whereKey($paid->id)->update(['payment_date' => '2026-07-01']),
            fn () => SalarySettlement::query()->whereKey($settlement->id)->update(['net_pay' => 1]),
            fn () => SalarySettlement::query()->whereKey($movableSettlement->id)->update(['salary_period_id' => $paid->id]),
            fn () => SalarySettlementItem::query()->whereKey($movableItem->id)->update(['salary_settlement_id' => $settlement->id]),
            fn () => SalarySettlementItem::query()->whereKey($item->id)->delete(),
            fn () => SalaryPeriod::query()->whereKey($paid->id)->delete(),
        ] as $write) {
            try {
                $write();
                $this->fail('已發薪歷史不得直接異動');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $this->assertDatabaseHas('salary_periods', ['id' => $paid->id, 'status' => SalaryPeriod::STATUS_PAID]);
        $this->assertDatabaseHas('salary_settlement_items', ['id' => $item->id]);
        $this->assertDatabaseHas('salary_settlements', [
            'id' => $movableSettlement->id,
            'salary_period_id' => $draft->id,
        ]);
        $this->assertDatabaseHas('salary_settlement_items', [
            'id' => $movableItem->id,
            'salary_settlement_id' => $movableSettlement->id,
        ]);
    }

    public function test_payment_accepts_a_canonical_integer_string_account_id(): void
    {
        $this->employeeWithProfile('員工', 30000);
        $period = $this->confirmedPeriod('2026-06');
        $account = CashAccount::factory()->create(['is_active' => true]);
        $payload = $this->payload($account, 'string-account-id');
        $payload['cash_account_id'] = (string) $account->id;

        $paid = $this->service->pay($this->admin, $period, $payload);

        $this->assertSame($account->id, $paid->cash_account_id);
        $this->assertSame($account->id, $paid->settlements->first()->moneyEntry->cash_account_id);
    }

    public function test_payment_date_must_be_between_period_start_and_today_but_may_be_in_a_later_month(): void
    {
        Carbon::setTestNow('2026-07-14 10:00:00');
        $this->employeeWithProfile('員工', 30000);
        $period = $this->confirmedPeriod('2026-06');
        $account = CashAccount::factory()->create(['is_active' => true]);

        foreach ([
            '2026-05-31' => '發薪日期不得早於結算月份第一天',
            '2026-07-15' => '日期不得晚於今天',
        ] as $paymentDate => $expectedMessage) {
            $payload = $this->payload($account, 'invalid-date-'.$paymentDate);
            $payload['payment_date'] = $paymentDate;

            try {
                $this->service->pay($this->admin, $period, $payload);
                $this->fail('不合理的發薪日期必須被拒絕');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($expectedMessage, $exception->errors()['payment_date'][0]);
            }
        }

        $delayedPayload = $this->payload($account, 'delayed-payment');
        $delayedPayload['payment_date'] = '2026-07-14';
        $paid = $this->service->pay($this->admin, $period, $delayedPayload);

        $this->assertSame('2026-07-14', $paid->payment_date->toDateString());
        $this->assertSame('2026-07-14', $paid->settlements->first()->moneyEntry->entry_date->toDateString());
        $this->assertSame(1, MoneyEntry::query()->where('source_type', MoneyEntry::SOURCE_SALARY_SETTLEMENT)->count());
    }

    public function test_paid_history_trigger_migration_is_safe_to_rerun_on_sqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('MySQL/MariaDB 重跑由專用可拋棄 schema 的並發測試覆蓋。');
        }

        $migrationPath = database_path('migrations/2026_07_13_000012_protect_paid_salary_history.php');
        (require $migrationPath)->up();
        (require $migrationPath)->up();

        $triggerCount = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', [
                'paid_salary_period_update', 'paid_salary_period_delete',
                'paid_salary_settlement_insert', 'paid_salary_settlement_update', 'paid_salary_settlement_delete',
                'paid_salary_item_insert', 'paid_salary_item_update', 'paid_salary_item_delete',
            ])
            ->count();
        $this->assertSame(8, $triggerCount);
    }

    private function employeeWithProfile(
        string $name,
        int $baseSalary,
        int $allowance = 0,
        int $labor = 0,
        int $health = 0,
    ): User {
        $user = User::factory()->sales()->create(['name' => $name, 'is_active' => true]);
        SalaryProfile::query()->create([
            'user_id' => $user->id,
            'base_salary' => $baseSalary,
            'fixed_allowance' => $allowance,
            'labor_insurance_deduction' => $labor,
            'health_insurance_deduction' => $health,
            'commission_enabled' => true,
            'is_active' => true,
        ]);

        return $user;
    }

    private function draftPeriod(string $month): SalaryPeriod
    {
        $plan = CommissionPlan::query()->create([
            'name' => '付款測試方案 '.$month,
            'effective_from' => $month.'-01',
            'company_reserve_bps' => 4000,
            'purchase_bonus_bps' => 2000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $plan->tiers()->create(['min_sales_count' => 1, 'sales_bonus_bps' => 2000, 'sort_order' => 1]);

        return $this->service->createDraft($this->admin, $month);
    }

    private function confirmedPeriod(string $month): SalaryPeriod
    {
        return $this->service->confirm($this->admin, $this->draftPeriod($month));
    }

    /** @return array{cash_account_id: int, payment_date: string, idempotency_key: string} */
    private function payload(CashAccount $account, string $key): array
    {
        return [
            'cash_account_id' => $account->id,
            'payment_date' => '2026-06-30',
            'idempotency_key' => $key,
        ];
    }

    private function expectValidation(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail("預期 {$field} 驗證錯誤");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
