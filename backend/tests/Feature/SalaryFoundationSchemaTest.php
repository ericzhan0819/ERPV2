<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\CommissionPlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalaryFoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_foundation_tables_have_all_required_columns(): void
    {
        $expected = [
            'salary_profiles' => [
                'id', 'user_id', 'base_salary', 'fixed_allowance',
                'labor_insurance_deduction', 'health_insurance_deduction',
                'commission_enabled', 'is_active', 'created_at', 'updated_at',
            ],
            'commission_plans' => [
                'id', 'name', 'effective_from', 'company_reserve_bps',
                'purchase_bonus_bps', 'is_active', 'created_by', 'created_at', 'updated_at',
            ],
            'commission_plan_tiers' => [
                'id', 'commission_plan_id', 'min_sales_count', 'sales_bonus_bps',
                'sort_order', 'created_at', 'updated_at',
            ],
            'salary_periods' => [
                'id', 'period_month', 'commission_plan_id', 'status',
                'company_reserve_total', 'company_remaining_total', 'created_by',
                'confirmed_by', 'confirmed_at', 'paid_by', 'paid_at', 'payment_date',
                'cash_account_id', 'idempotency_key', 'created_at', 'updated_at',
            ],
            'salary_settlements' => [
                'id', 'salary_period_id', 'user_id', 'eligible_sales_count',
                'sales_bonus_bps_snapshot', 'base_salary_snapshot', 'fixed_allowance_snapshot',
                'labor_insurance_deduction_snapshot', 'health_insurance_deduction_snapshot',
                'purchase_bonus_total', 'sales_bonus_total', 'manual_addition_total',
                'manual_deduction_total', 'gross_pay', 'deduction_total', 'net_pay',
                'money_entry_id', 'created_at', 'updated_at',
            ],
            'salary_settlement_items' => [
                'id', 'salary_settlement_id', 'type', 'vehicle_id', 'amount',
                'description', 'calculation_snapshot', 'created_by', 'created_at', 'updated_at',
            ],
        ];

        foreach ($expected as $table => $columns) {
            $this->assertTrue(Schema::hasColumns($table, $columns), "{$table} 缺少必要欄位");
        }

        $this->assertTrue(Schema::hasColumns('vehicles', ['purchase_agent_id', 'sales_agent_id']));
    }

    public function test_salary_profile_defaults_use_integer_amounts_and_user_is_unique(): void
    {
        $user = User::factory()->create();
        $now = now();

        DB::table('salary_profiles')->insert([
            'user_id' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $profile = DB::table('salary_profiles')->where('user_id', $user->id)->first();
        $this->assertSame(0, (int) $profile->base_salary);
        $this->assertSame(0, (int) $profile->fixed_allowance);
        $this->assertSame(0, (int) $profile->labor_insurance_deduction);
        $this->assertSame(0, (int) $profile->health_insurance_deduction);
        $this->assertSame(1, (int) $profile->commission_enabled);
        $this->assertSame(1, (int) $profile->is_active);

        $this->expectException(QueryException::class);
        DB::table('salary_profiles')->insert([
            'user_id' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_vehicle_agent_foreign_keys_are_nullable_restrict_and_not_backfilled(): void
    {
        $agent = User::factory()->create();
        $vehicle = Vehicle::factory()->create();

        $this->assertNull($vehicle->purchase_agent_id);
        $this->assertNull($vehicle->sales_agent_id);

        $vehicle->update([
            'purchase_agent_id' => $agent->id,
            'sales_agent_id' => $agent->id,
        ]);

        $this->expectException(QueryException::class);
        $agent->delete();
    }

    public function test_period_status_month_and_settlement_uniqueness_are_enforced(): void
    {
        [$admin, $planId] = $this->seedStandardPlan();
        $now = now();

        $periodId = DB::table('salary_periods')->insertGetId([
            'period_month' => '2026-07-01',
            'commission_plan_id' => $planId,
            'status' => 'draft',
            'created_by' => $admin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $employee = User::factory()->create();
        DB::table('salary_settlements')->insert([
            'salary_period_id' => $periodId,
            'user_id' => $employee->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            DB::table('salary_periods')->where('id', $periodId)->update(['status' => 'invalid']);
            $this->fail('invalid salary period status 應被 DB 拒絕');
        } catch (QueryException) {
            $this->assertSame('draft', DB::table('salary_periods')->where('id', $periodId)->value('status'));
        }

        try {
            DB::table('salary_periods')->where('id', $periodId)->update(['period_month' => '2026-07-02']);
            $this->fail('period_month 必須使用每月第一天');
        } catch (QueryException) {
            $this->assertSame('2026-07-01', DB::table('salary_periods')->where('id', $periodId)->value('period_month'));
        }

        $this->expectException(QueryException::class);
        DB::table('salary_settlements')->insert([
            'salary_period_id' => $periodId,
            'user_id' => $employee->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_vehicle_bonus_item_cannot_repeat_within_same_settlement(): void
    {
        [$admin, $planId] = $this->seedStandardPlan();
        $employee = User::factory()->create();
        $vehicle = Vehicle::factory()->create();
        $now = now();
        $periodId = $this->createPeriod($admin, $planId);
        $settlementId = DB::table('salary_settlements')->insertGetId([
            'salary_period_id' => $periodId,
            'user_id' => $employee->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $item = [
            'salary_settlement_id' => $settlementId,
            'type' => 'purchase_bonus',
            'vehicle_id' => $vehicle->id,
            'amount' => 100,
            'description' => '收車獎金',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('salary_settlement_items')->insert($item);

        $this->expectException(QueryException::class);
        DB::table('salary_settlement_items')->insert($item);
    }

    public function test_referenced_commission_plan_and_tiers_are_immutable(): void
    {
        [$admin, $planId] = $this->seedStandardPlan();
        $this->createPeriod($admin, $planId);

        try {
            DB::table('commission_plans')->where('id', $planId)->update(['purchase_bonus_bps' => 2500]);
            $this->fail('被薪資月份引用的方案計算欄位不得修改');
        } catch (QueryException) {
            $this->assertSame(2000, (int) DB::table('commission_plans')->where('id', $planId)->value('purchase_bonus_bps'));
        }

        $this->expectException(QueryException::class);
        DB::table('commission_plan_tiers')->where('commission_plan_id', $planId)->limit(1)->delete();
    }

    public function test_standard_commission_plan_seeder_is_rerunnable_and_exact(): void
    {
        [$admin, $planId] = $this->seedStandardPlan();
        $this->seedStandardPlan();

        $this->assertSame(1, DB::table('commission_plans')->where('name', '2026 標準薪資方案')->count());
        $this->assertSame(3, DB::table('commission_plan_tiers')->where('commission_plan_id', $planId)->count());
        $this->assertSame([1, 3, 5], DB::table('commission_plan_tiers')->where('commission_plan_id', $planId)->orderBy('sort_order')->pluck('min_sales_count')->map(fn ($value) => (int) $value)->all());
        $this->assertSame([2000, 3000, 5000], DB::table('commission_plan_tiers')->where('commission_plan_id', $planId)->orderBy('sort_order')->pluck('sales_bonus_bps')->map(fn ($value) => (int) $value)->all());

        $this->createPeriod($admin, $planId);
        $this->seedStandardPlan();
        $this->assertSame(3, DB::table('commission_plan_tiers')->where('commission_plan_id', $planId)->count());
    }

    public function test_remaining_unique_and_foreign_key_contracts_are_enforced(): void
    {
        [$admin, $planId] = $this->seedStandardPlan();
        $now = now();

        try {
            DB::table('commission_plan_tiers')->insert([
                'commission_plan_id' => $planId,
                'min_sales_count' => 1,
                'sales_bonus_bps' => 1000,
                'sort_order' => 99,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fail('同方案 min_sales_count 必須唯一');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $periodId = $this->createPeriod($admin, $planId);
        try {
            $this->createPeriod($admin, $planId);
            $this->fail('每月只能有一個 salary period');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $account = CashAccount::factory()->create();
        $entry = MoneyEntry::factory()->create([
            'cash_account_id' => $account->id,
            'direction' => 'expense',
            'category' => '薪資 / 佣金',
            'source_type' => MoneyEntry::SOURCE_SALARY_SETTLEMENT,
            'approval_status' => MoneyEntry::APPROVAL_APPROVED,
            'created_by' => $admin->id,
        ]);
        $firstEmployee = User::factory()->create();
        $secondEmployee = User::factory()->create();
        DB::table('salary_settlements')->insert([
            'salary_period_id' => $periodId,
            'user_id' => $firstEmployee->id,
            'money_entry_id' => $entry->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            DB::table('salary_settlements')->insert([
                'salary_period_id' => $periodId,
                'user_id' => $secondEmployee->id,
                'money_entry_id' => $entry->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fail('money_entry_id 必須唯一');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('commission_plans')->where('id', $planId)->delete();
            $this->fail('被 salary period 引用的方案不得刪除');
        } catch (QueryException) {
            $this->assertDatabaseHas('commission_plans', ['id' => $planId]);
        }

        $unusedPlanId = DB::table('commission_plans')->insertGetId([
            'name' => '未使用方案',
            'effective_from' => '2099-01-01',
            'company_reserve_bps' => 4000,
            'purchase_bonus_bps' => 2000,
            'is_active' => false,
            'created_by' => $admin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('commission_plan_tiers')->insert([
            'commission_plan_id' => $unusedPlanId,
            'min_sales_count' => 1,
            'sales_bonus_bps' => 2000,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('commission_plans')->where('id', $unusedPlanId)->delete();
        $this->assertDatabaseMissing('commission_plan_tiers', ['commission_plan_id' => $unusedPlanId]);
    }

    /** @return array{User, int} */
    private function seedStandardPlan(): array
    {
        $admin = User::query()->where('role', User::ROLE_ADMIN)->first()
            ?? User::factory()->admin()->create();
        $this->seed(CommissionPlanSeeder::class);

        return [$admin, (int) DB::table('commission_plans')->where('name', '2026 標準薪資方案')->value('id')];
    }

    private function createPeriod(User $admin, int $planId): int
    {
        return DB::table('salary_periods')->insertGetId([
            'period_month' => '2026-07-01',
            'commission_plan_id' => $planId,
            'status' => 'draft',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
