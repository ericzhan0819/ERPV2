<?php

namespace Tests\Feature;

use App\Http\Requests\PaySalaryPeriodRequest;
use App\Http\Requests\StoreSalaryAdjustmentRequest;
use App\Http\Requests\StoreSalaryPeriodRequest;
use App\Http\Requests\UpdateVehicleCommissionAttributionRequest;
use App\Http\Resources\SalaryPeriodListResource;
use App\Http\Resources\SalaryPeriodResource;
use App\Models\CashAccount;
use App\Models\CommissionPlan;
use App\Models\SalaryPeriod;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Policies\SalaryPeriodPolicy;
use App\Policies\SalarySettlementPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalaryHttpBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_period_and_settlement_policies_are_admin_only_and_unknown_roles_fail_closed(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $sales = User::factory()->sales()->create();
        $period = $this->period($admin);
        $settlement = SalarySettlement::query()->create([
            'salary_period_id' => $period->id,
            'user_id' => $admin->id,
        ]);

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', SalaryPeriod::class));
        $this->assertTrue(Gate::forUser($admin)->allows('create', SalaryPeriod::class));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $period));
        $this->assertTrue(Gate::forUser($admin)->allows('recalculate', $period));
        $this->assertTrue(Gate::forUser($admin)->allows('confirm', $period));
        $this->assertTrue(Gate::forUser($admin)->allows('pay', $period));
        $this->assertTrue(Gate::forUser($admin)->allows('adjust', $settlement));

        foreach ([$manager, $sales] as $user) {
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', SalaryPeriod::class));
            $this->assertFalse(Gate::forUser($user)->allows('view', $period));
            $this->assertFalse(Gate::forUser($user)->allows('adjust', $settlement));
        }

        $unknown = new User(['role' => 'unknown']);
        $this->assertFalse((new SalaryPeriodPolicy)->view($unknown, $period));
        $this->assertFalse((new SalarySettlementPolicy)->adjust($unknown, $settlement));
    }

    public function test_salary_requests_accept_only_business_inputs_and_reject_system_fields_with_chinese_messages(): void
    {
        $user = User::factory()->create();
        $activeAccount = CashAccount::factory()->create(['is_active' => true]);
        $inactiveAccount = CashAccount::factory()->create(['is_active' => false]);

        $this->assertRequestPasses(StoreSalaryPeriodRequest::class, ['period_month' => '2026-06']);
        $this->assertRequestFails(
            StoreSalaryPeriodRequest::class,
            ['period_month' => '2026/06', 'status' => 'paid'],
            ['period_month', 'status'],
        );

        $this->assertRequestPasses(StoreSalaryAdjustmentRequest::class, [
            'user_id' => $user->id,
            'type' => SalarySettlementItem::TYPE_MANUAL_ADDITION,
            'amount' => 1000,
            'description' => '臨時加給',
        ]);
        $adjustmentErrors = $this->assertRequestFails(StoreSalaryAdjustmentRequest::class, [
            'user_id' => $user->id,
            'type' => SalarySettlementItem::TYPE_BASE_SALARY,
            'amount' => 0,
            'description' => '',
            'calculation_snapshot' => ['gross_profit' => 999999],
        ], ['type', 'amount', 'description', 'calculation_snapshot']);
        $this->assertSame('項目類型只允許其他加給或其他扣款', $adjustmentErrors['type'][0]);

        $this->assertRequestPasses(PaySalaryPeriodRequest::class, [
            'cash_account_id' => (string) $activeAccount->id,
            'payment_date' => '2026-07-01',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $paymentErrors = $this->assertRequestFails(PaySalaryPeriodRequest::class, [
            'cash_account_id' => $inactiveAccount->id,
            'payment_date' => '07/01/2026',
            'idempotency_key' => str_repeat('x', 101),
            'paid_by' => $user->id,
        ], ['cash_account_id', 'payment_date', 'idempotency_key', 'paid_by']);
        $this->assertSame('請選擇啟用中的資金帳戶', $paymentErrors['cash_account_id'][0]);

        $this->assertRequestPasses(UpdateVehicleCommissionAttributionRequest::class, [
            'purchase_agent_id' => $user->id,
        ]);
        $this->assertRequestFails(UpdateVehicleCommissionAttributionRequest::class, [
            'sales_agent_id' => $user->id,
            'status' => 'sold',
            'snapshot' => [],
        ], ['status', 'snapshot']);
    }

    public function test_salary_period_resource_exposes_live_draft_anomalies_and_only_whitelisted_salary_data(): void
    {
        $admin = User::factory()->admin()->create();
        $period = $this->period($admin);
        $vehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-06-15 10:00:00',
            'purchase_agent_id' => null,
            'sales_agent_id' => null,
            'purchase_price' => 100000,
            'sold_price' => 150000,
        ]);
        $settlement = SalarySettlement::query()->create([
            'salary_period_id' => $period->id,
            'user_id' => $admin->id,
            'eligible_sales_count' => 1,
            'sales_bonus_bps_snapshot' => 2000,
            'base_salary_snapshot' => 35000,
            'purchase_bonus_total' => 1000,
            'sales_bonus_total' => 2000,
            'gross_pay' => 38000,
            'net_pay' => 38000,
            'money_entry_id' => null,
        ]);
        SalarySettlementItem::query()->create([
            'salary_settlement_id' => $settlement->id,
            'type' => SalarySettlementItem::TYPE_SALES_BONUS,
            'vehicle_id' => $vehicle->id,
            'amount' => 2000,
            'description' => '車輛賣車獎金',
            'calculation_snapshot' => [
                'period_month' => '2026-06',
                'gross_profit' => 50000,
                'sales_bonus' => 2000,
                'internal_secret' => '不可輸出',
            ],
        ]);

        $period->load([
            'plan.tiers',
            'plan.createdBy',
            'settlements.user',
            'settlements.items.vehicle',
            'settlements.items.createdBy',
            'createdBy',
            'confirmedBy',
            'paidBy',
            'cashAccount',
        ]);
        $data = $this->resourceData(new SalaryPeriodResource($period), $admin);

        $this->assertTrue($data['has_blocking_issues']);
        $this->assertNotEmpty($data['anomalies']);
        $this->assertSame($vehicle->id, $data['vehicle_results'][0]['vehicle_id']);
        $this->assertSame('commission_attribution', collect($data['anomalies'])->firstWhere('code', 'purchase_agent_missing')['correction']['action']);
        $this->assertSame(38000, $data['totals']['net_pay']);
        $this->assertArrayNotHasKey('idempotency_key', $data);
        $this->assertArrayNotHasKey('commission_plan_id', $data);
        $this->assertArrayNotHasKey('money_entry_id', $data['settlements'][0]);
        $this->assertArrayNotHasKey('calculation_snapshot', $data['settlements'][0]['items'][0]);
        $this->assertArrayNotHasKey('internal_secret', $data['settlements'][0]['items'][0]['calculation']);

        $list = $this->resourceData(new SalaryPeriodListResource($period), $admin);
        $this->assertSame(1, $list['settlement_count']);
        $this->assertSame(38000, $list['net_pay_total']);
        $this->assertArrayNotHasKey('idempotency_key', $list);
        $this->assertArrayNotHasKey('settlements', $list);
    }

    public function test_confirmed_period_resource_does_not_recalculate_or_expose_live_draft_anomalies(): void
    {
        $admin = User::factory()->admin()->create();
        $period = $this->period($admin);
        $period->update(['status' => SalaryPeriod::STATUS_CONFIRMED]);
        Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-06-15',
            'purchase_agent_id' => null,
            'sales_agent_id' => null,
        ]);

        $data = $this->resourceData(new SalaryPeriodResource($period), $admin);

        $this->assertArrayNotHasKey('anomalies', $data);
        $this->assertArrayNotHasKey('vehicle_results', $data);
        $this->assertArrayNotHasKey('has_blocking_issues', $data);
    }

    /** @return array<string, array<int, string>> */
    private function assertRequestFails(string $requestClass, array $payload, array $fields): array
    {
        $validator = $this->validator($requestClass, $payload);
        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $errors);
        }

        return $errors;
    }

    private function assertRequestPasses(string $requestClass, array $payload): void
    {
        $this->assertFalse($this->validator($requestClass, $payload)->fails());
    }

    private function validator(string $requestClass, array $payload): \Illuminate\Contracts\Validation\Validator
    {
        $request = $requestClass::create('/api/test', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules(), $request->messages(), $request->attributes());
        if (method_exists($request, 'after')) {
            $validator->after($request->after());
        }

        return $validator;
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create('/api/salary-periods/1', 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /** @return array<string, mixed> */
    private function resourceData(object $resource, User $user): array
    {
        $response = $resource->response($this->requestFor($user));

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }

    private function period(User $admin): SalaryPeriod
    {
        $plan = CommissionPlan::query()->create([
            'name' => '測試薪資方案 '.Str::uuid(),
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

        return SalaryPeriod::query()->create([
            'period_month' => '2026-06-01',
            'commission_plan_id' => $plan->id,
            'status' => SalaryPeriod::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
    }
}
