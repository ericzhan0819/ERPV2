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
use App\Models\MoneyEntry;
use App\Models\SalaryPeriod;
use App\Models\SalaryProfile;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Policies\SalaryPeriodPolicy;
use App\Policies\SalarySettlementPolicy;
use App\Services\SalaryPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalaryHttpBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/_test/salary-period', fn (StoreSalaryPeriodRequest $request) => $request->validated());
        Route::post('/api/_test/salary-adjustment', fn (StoreSalaryAdjustmentRequest $request) => $request->validated());
        Route::post('/api/_test/salary-payment', fn (PaySalaryPeriodRequest $request) => $request->validated());
        Route::patch('/api/_test/commission-attribution', fn (UpdateVehicleCommissionAttributionRequest $request) => $request->validated());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
        $this->assertTrue(Gate::forUser($admin)->allows('deleteAdjustment', $settlement));

        foreach ([$manager, $sales] as $user) {
            $this->assertFalse(Gate::forUser($user)->allows('viewAny', SalaryPeriod::class));
            $this->assertFalse(Gate::forUser($user)->allows('create', SalaryPeriod::class));
            $this->assertFalse(Gate::forUser($user)->allows('view', $period));
            $this->assertFalse(Gate::forUser($user)->allows('recalculate', $period));
            $this->assertFalse(Gate::forUser($user)->allows('confirm', $period));
            $this->assertFalse(Gate::forUser($user)->allows('pay', $period));
            $this->assertFalse(Gate::forUser($user)->allows('adjust', $settlement));
            $this->assertFalse(Gate::forUser($user)->allows('deleteAdjustment', $settlement));
        }

        $unknown = new User(['role' => 'unknown']);
        $this->assertFalse((new SalaryPeriodPolicy)->view($unknown, $period));
        $this->assertFalse((new SalarySettlementPolicy)->adjust($unknown, $settlement));
        $this->assertFalse((new SalarySettlementPolicy)->deleteAdjustment($unknown, $settlement));
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

        $adjustmentResponse = $this->assertRequestPasses(StoreSalaryAdjustmentRequest::class, [
            'user_id' => $user->id,
            'type' => SalarySettlementItem::TYPE_MANUAL_ADDITION,
            'amount' => '1000',
            'description' => '臨時加給',
        ]);
        $adjustmentResponse->assertJsonPath('amount', 1000);
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

    public function test_numeric_string_adjustment_amount_is_normalized_before_reaching_salary_service(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $employee = User::factory()->sales()->create(['is_active' => true]);
        $this->profile($employee);
        $this->plan($admin);
        $period = app(SalaryPeriodService::class)->createDraft($admin, '2026-06');
        $settlement = $period->settlements->firstWhere('user_id', $employee->id);

        $response = $this->postJson('/api/_test/salary-adjustment', [
            'user_id' => $employee->id,
            'type' => SalarySettlementItem::TYPE_MANUAL_ADDITION,
            'amount' => '1000',
            'description' => '臨時加給',
        ])->assertOk()->assertJsonPath('amount', 1000);

        $item = app(SalaryPeriodService::class)->addAdjustment($admin, $settlement, $response->json());

        $this->assertSame(1000, $item->amount);
        $this->assertSame(1000, $settlement->fresh()->manual_addition_total);
    }

    public function test_salary_period_cannot_be_later_than_current_taipei_month_but_current_month_is_allowed(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 1, 0, 30, 0, 'Asia/Taipei'));
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $this->plan($admin);

        $this->assertRequestPasses(StoreSalaryPeriodRequest::class, ['period_month' => '2026-07']);
        $errors = $this->assertRequestFails(
            StoreSalaryPeriodRequest::class,
            ['period_month' => '2026-08'],
            ['period_month'],
        );
        $this->assertSame('結算月份不得晚於台北目前月份', $errors['period_month'][0]);

        $service = app(SalaryPeriodService::class);
        $this->assertSame('2026-07', $service->createDraft($admin, '2026-07')->period_month->format('Y-m'));

        try {
            $service->createDraft($admin, '2026-08');
            $this->fail('Service 不得建立晚於台北目前月份的薪資草稿');
        } catch (ValidationException $exception) {
            $this->assertSame(
                '結算月份不得晚於台北目前月份（2026-07）',
                $exception->errors()['period_month'][0],
            );
        }
    }

    public function test_salary_period_resource_exposes_live_draft_anomalies_and_only_whitelisted_salary_data(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $employee = User::factory()->sales()->create(['is_active' => true]);
        $this->profile($employee);
        $this->plan($admin);
        $eligibleVehicle = $this->validVehicle($employee);
        $anomalousVehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-06-15 10:00:00',
            'purchase_agent_id' => null,
            'sales_agent_id' => null,
            'purchase_price' => 100000,
            'sold_price' => 150000,
        ]);
        $period = app(SalaryPeriodService::class)->createDraft($admin, '2026-06');
        $bonusItem = $period->settlements
            ->firstWhere('user_id', $employee->id)
            ->items
            ->firstWhere('type', SalarySettlementItem::TYPE_SALES_BONUS);
        $bonusItem->forceFill([
            'calculation_snapshot' => [
                ...$bonusItem->calculation_snapshot,
                'internal_secret' => '不可輸出',
            ],
        ])->save();
        $data = $this->resourceData(new SalaryPeriodResource($period), $admin);

        $this->assertTrue($data['has_blocking_issues']);
        $this->assertNotEmpty($data['anomalies']);
        $this->assertContains($anomalousVehicle->id, collect($data['vehicle_results'])->pluck('vehicle_id'));
        $this->assertSame('commission_attribution', collect($data['anomalies'])->firstWhere('code', 'purchase_agent_missing')['correction']['action']);
        $this->assertSame($admin->id, $data['created_by']['id']);
        $this->assertSame($admin->id, $data['commission_plan']['created_by']['id']);
        $this->assertSame(47000, $data['totals']['net_pay']);
        $this->assertSame(20000, $data['totals']['company_reserve_total']);
        $this->assertSame(18000, $data['totals']['company_remaining_total']);
        $this->assertArrayNotHasKey('idempotency_key', $data);
        $this->assertArrayNotHasKey('commission_plan_id', $data);
        $settlement = collect($data['settlements'])->firstWhere('user_id', $employee->id);
        $bonus = collect($settlement['items'])->firstWhere('type', SalarySettlementItem::TYPE_SALES_BONUS);
        $this->assertSame($eligibleVehicle->id, $bonus['vehicle']['id']);
        $this->assertSame($eligibleVehicle->stock_no, $bonus['vehicle']['stock_no']);
        $this->assertArrayNotHasKey('money_entry_id', $settlement);
        $this->assertArrayNotHasKey('calculation_snapshot', $bonus);
        $this->assertArrayNotHasKey('internal_secret', $bonus['calculation']);

        $list = $this->resourceData(new SalaryPeriodListResource($period), $admin);
        $this->assertSame(1, $list['settlement_count']);
        $this->assertSame(47000, $list['net_pay_total']);
        $this->assertArrayNotHasKey('idempotency_key', $list);
        $this->assertArrayNotHasKey('settlements', $list);
    }

    public function test_company_totals_include_eligible_vehicle_when_both_agents_have_no_active_salary_profile(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $agentWithoutProfile = User::factory()->sales()->create(['is_active' => true]);
        $this->plan($admin);
        $this->validVehicle($agentWithoutProfile);

        $service = app(SalaryPeriodService::class);
        $draft = $service->createDraft($admin, '2026-06');
        $draftData = $this->resourceData(new SalaryPeriodResource($draft), $admin);

        $this->assertFalse($draftData['has_blocking_issues']);
        $this->assertSame([], $draftData['settlements']);
        $this->assertSame(20000, $draftData['totals']['company_reserve_total']);
        $this->assertSame(30000, $draftData['totals']['company_remaining_total']);
        $this->assertTrue($draftData['totals']['company_totals_available']);

        $confirmed = $service->confirm($admin, $draft);
        $confirmedData = $this->resourceData(new SalaryPeriodResource($confirmed), $admin);
        $this->assertSame('confirmed', $confirmedData['status']);
        $this->assertSame(20000, $confirmedData['totals']['company_reserve_total']);
        $this->assertSame(30000, $confirmedData['totals']['company_remaining_total']);
    }

    public function test_paid_period_service_result_contains_resource_actor_account_and_vehicle_relations(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $employee = User::factory()->sales()->create(['is_active' => true]);
        $this->profile($employee);
        $this->plan($admin);
        $vehicle = $this->validVehicle($employee);
        $account = CashAccount::factory()->create(['is_active' => true]);
        $service = app(SalaryPeriodService::class);

        $draft = $service->createDraft($admin, '2026-06');
        $service->addAdjustment($admin, $draft->settlements->firstWhere('user_id', $employee->id), [
            'type' => SalarySettlementItem::TYPE_MANUAL_ADDITION,
            'amount' => 500,
            'description' => '測試加給',
        ]);
        $confirmed = $service->confirm($admin, $draft);
        $paid = $service->pay($admin, $confirmed, [
            'cash_account_id' => $account->id,
            'payment_date' => '2026-07-01',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $data = $this->resourceData(new SalaryPeriodResource($paid), $admin);

        $this->assertSame($admin->id, $data['created_by']['id']);
        $this->assertSame($admin->id, $data['confirmed_by']['id']);
        $this->assertSame($admin->id, $data['paid_by']['id']);
        $this->assertSame($account->id, $data['cash_account']['id']);
        $settlement = collect($data['settlements'])->firstWhere('user_id', $employee->id);
        $bonus = collect($settlement['items'])->firstWhere('type', SalarySettlementItem::TYPE_SALES_BONUS);
        $adjustment = collect($settlement['items'])->firstWhere('type', SalarySettlementItem::TYPE_MANUAL_ADDITION);
        $this->assertSame($vehicle->id, $bonus['vehicle']['id']);
        $this->assertSame($admin->id, $adjustment['created_by']['id']);
        $this->assertTrue($settlement['has_payment_entry']);
        $this->assertArrayNotHasKey('money_entry_id', $settlement);
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

    public function test_admin_can_use_the_complete_salary_period_http_workflow(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 10, 0, 0, 'Asia/Taipei'));
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $employee = User::factory()->sales()->create(['is_active' => true]);
        $this->profile($employee);
        $this->plan($admin);
        $this->validVehicle($employee);
        $account = CashAccount::factory()->create(['is_active' => true]);

        $created = $this->actingAs($admin)->postJson('/api/salary-periods', [
            'period_month' => '2026-06',
        ])->assertCreated()->assertJsonPath('data.status', SalaryPeriod::STATUS_DRAFT);
        $periodId = $created->json('data.id');

        $this->getJson('/api/salary-periods')
            ->assertOk()
            ->assertJsonPath('data.0.id', $periodId)
            ->assertJsonMissingPath('data.0.settlements');
        $this->getJson("/api/salary-periods/{$periodId}")
            ->assertOk()
            ->assertJsonPath('data.period_month', '2026-06');

        $adjustment = $this->postJson("/api/salary-periods/{$periodId}/adjustments", [
            'user_id' => $employee->id,
            'type' => SalarySettlementItem::TYPE_MANUAL_ADDITION,
            'amount' => '1000',
            'description' => '臨時加給',
        ])->assertCreated()->assertJsonPath('data.amount', 1000);
        $this->assertSame([
            'id',
            'type',
            'vehicle_id',
            'vehicle',
            'amount',
            'description',
            'calculation',
            'created_by',
            'created_at',
        ], array_keys($adjustment->json('data')));
        $adjustment->assertJsonPath('data.vehicle', null);
        $itemId = $adjustment->json('data.id');

        $this->deleteJson("/api/salary-periods/{$periodId}/adjustments/{$itemId}")
            ->assertOk()
            ->assertJsonPath('message', '手動薪資加扣項已刪除');
        $this->postJson("/api/salary-periods/{$periodId}/recalculate")
            ->assertOk()
            ->assertJsonPath('data.status', SalaryPeriod::STATUS_DRAFT);
        $this->postJson("/api/salary-periods/{$periodId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', SalaryPeriod::STATUS_CONFIRMED);
        $this->postJson("/api/salary-periods/{$periodId}/pay", [
            'cash_account_id' => $account->id,
            'payment_date' => '2026-07-01',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.status', SalaryPeriod::STATUS_PAID);
    }

    public function test_manager_and_sales_receive_forbidden_for_every_salary_period_endpoint(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $manager = User::factory()->manager()->create(['is_active' => true]);
        $sales = User::factory()->sales()->create(['is_active' => true]);
        $period = $this->period($admin);
        $settlement = SalarySettlement::query()->create([
            'salary_period_id' => $period->id,
            'user_id' => $sales->id,
        ]);
        $item = $settlement->items()->create([
            'type' => SalarySettlementItem::TYPE_MANUAL_ADDITION,
            'amount' => 100,
            'description' => '權限測試',
            'created_by' => $admin->id,
        ]);
        $account = CashAccount::factory()->create(['is_active' => true]);
        $vehicle = Vehicle::factory()->create();

        foreach ([$manager, $sales] as $user) {
            $requests = [
                fn () => $this->getJson('/api/salary-periods'),
                fn () => $this->postJson('/api/salary-periods', ['period_month' => '2026-06']),
                fn () => $this->getJson("/api/salary-periods/{$period->id}"),
                fn () => $this->postJson("/api/salary-periods/{$period->id}/recalculate"),
                fn () => $this->postJson("/api/salary-periods/{$period->id}/adjustments", [
                    'user_id' => $sales->id,
                    'type' => SalarySettlementItem::TYPE_MANUAL_ADDITION,
                    'amount' => 100,
                    'description' => '未授權',
                ]),
                fn () => $this->deleteJson("/api/salary-periods/{$period->id}/adjustments/{$item->id}"),
                fn () => $this->postJson("/api/salary-periods/{$period->id}/confirm"),
                fn () => $this->postJson("/api/salary-periods/{$period->id}/pay", [
                    'cash_account_id' => $account->id,
                    'payment_date' => '2026-07-01',
                    'idempotency_key' => (string) Str::uuid(),
                ]),
                fn () => $this->patchJson("/api/vehicles/{$vehicle->id}/commission-attribution", [
                    'purchase_agent_id' => $sales->id,
                ]),
            ];

            $this->actingAs($user);
            foreach ($requests as $request) {
                $response = $request()->assertForbidden();
                $keys = $this->recursiveJsonKeys($response->json());
                foreach (['base_salary', 'net_pay', 'money_entry_id', 'calculation_snapshot', 'idempotency_key'] as $key) {
                    $this->assertNotContains($key, $keys);
                }
            }
            $this->getJson('/api/salary-periods/999999999')->assertForbidden();
            $this->deleteJson("/api/salary-periods/999999999/adjustments/{$item->id}")->assertForbidden();
            $this->patchJson('/api/vehicles/999999999/commission-attribution', [
                'purchase_agent_id' => $sales->id,
            ])->assertForbidden();
        }
    }

    public function test_salary_period_index_uses_list_resource_without_inspecting_each_period(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $plan = $this->plan($admin);
        SalaryPeriod::query()->create([
            'period_month' => '2026-05-01',
            'commission_plan_id' => $plan->id,
            'status' => SalaryPeriod::STATUS_CONFIRMED,
            'created_by' => $admin->id,
        ]);
        SalaryPeriod::query()->create([
            'period_month' => '2026-06-01',
            'commission_plan_id' => $plan->id,
            'status' => SalaryPeriod::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->actingAs($admin)->getJson('/api/salary-periods')->assertOk();

        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.period_month', '2026-06');
        $response->assertJsonMissingPath('data.0.anomalies');
        $response->assertJsonMissingPath('data.0.settlements');
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, ' from "vehicles"')
                || str_contains($sql, ' from `vehicles`')
                || str_contains($sql, ' from "money_entries"')
                || str_contains($sql, ' from `money_entries`'),
        ));
    }

    public function test_draft_write_responses_reuse_transaction_eligibility_but_show_inspects_live_data(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $employee = User::factory()->sales()->create(['is_active' => true]);
        $this->profile($employee);
        $this->plan($admin);
        $this->validVehicle($employee);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $created = $this->actingAs($admin)->postJson('/api/salary-periods', [
            'period_month' => '2026-06',
        ])->assertCreated();
        $periodId = $created->json('data.id');
        $this->assertSame(1, $this->eligibilityVehicleScanCount($queries), '建立草稿不應重複掃描候選車輛');
        $this->assertSame(1, $this->eligibilityMoneyScanCount($queries), '建立草稿不應重複掃描資格收支');

        $queries = [];
        $this->getJson("/api/salary-periods/{$periodId}")->assertOk();
        $this->assertSame(1, $this->eligibilityVehicleScanCount($queries), 'GET 詳情應即時掃描一次候選車輛');
        $this->assertSame(1, $this->eligibilityMoneyScanCount($queries), 'GET 詳情應即時掃描一次資格收支');

        $queries = [];
        $this->postJson("/api/salary-periods/{$periodId}/recalculate")->assertOk();
        $this->assertSame(1, $this->eligibilityVehicleScanCount($queries), '重算不應重複掃描候選車輛');
        $this->assertSame(1, $this->eligibilityMoneyScanCount($queries), '重算不應重複掃描資格收支');
    }

    public function test_salary_period_show_uses_raw_json_whitelist(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $employee = User::factory()->sales()->create(['is_active' => true]);
        $this->profile($employee);
        $this->plan($admin);
        $this->validVehicle($employee);
        $period = app(SalaryPeriodService::class)->createDraft($admin, '2026-06');
        $bonus = $period->settlements->firstWhere('user_id', $employee->id)->items
            ->firstWhere('type', SalarySettlementItem::TYPE_SALES_BONUS);
        $bonus->forceFill([
            'calculation_snapshot' => [
                ...$bonus->calculation_snapshot,
                'internal_secret' => '不可輸出',
            ],
        ])->save();

        $response = $this->actingAs($admin)->getJson("/api/salary-periods/{$period->id}")->assertOk();
        $keys = $this->recursiveJsonKeys($response->json('data'));

        foreach (['idempotency_key', 'money_entry_id', 'calculation_snapshot', 'internal_secret'] as $key) {
            $this->assertNotContains($key, $keys);
        }
    }

    public function test_adjustment_item_cannot_be_deleted_through_another_period_route(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $employee = User::factory()->sales()->create(['is_active' => true]);
        $firstPeriod = $this->period($admin);
        $secondPeriod = SalaryPeriod::query()->create([
            'period_month' => '2026-05-01',
            'commission_plan_id' => $firstPeriod->commission_plan_id,
            'status' => SalaryPeriod::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
        $settlement = SalarySettlement::query()->create([
            'salary_period_id' => $secondPeriod->id,
            'user_id' => $employee->id,
        ]);
        $item = $settlement->items()->create([
            'type' => SalarySettlementItem::TYPE_MANUAL_ADDITION,
            'amount' => 1000,
            'description' => '跨月份保護測試',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/salary-periods/{$firstPeriod->id}/adjustments/{$item->id}")
            ->assertNotFound();
        $this->assertDatabaseHas('salary_settlement_items', ['id' => $item->id]);

        $this->deleteJson("/api/salary-periods/{$secondPeriod->id}/adjustments/{$item->id}")
            ->assertOk();
        $this->assertDatabaseMissing('salary_settlement_items', ['id' => $item->id]);
    }

    /** @return array<string, array<int, string>> */
    private function assertRequestFails(string $requestClass, array $payload, array $fields): array
    {
        $response = $this->requestForClass($requestClass, $payload)->assertUnprocessable();
        $errors = $response->json('errors');
        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $errors);
        }

        return $errors;
    }

    private function assertRequestPasses(string $requestClass, array $payload): TestResponse
    {
        return $this->requestForClass($requestClass, $payload)->assertOk();
    }

    private function requestForClass(string $requestClass, array $payload): TestResponse
    {
        return match ($requestClass) {
            StoreSalaryPeriodRequest::class => $this->postJson('/api/_test/salary-period', $payload),
            StoreSalaryAdjustmentRequest::class => $this->postJson('/api/_test/salary-adjustment', $payload),
            PaySalaryPeriodRequest::class => $this->postJson('/api/_test/salary-payment', $payload),
            UpdateVehicleCommissionAttributionRequest::class => $this->patchJson('/api/_test/commission-attribution', $payload),
            default => throw new \InvalidArgumentException("未註冊測試 FormRequest：{$requestClass}"),
        };
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

    private function profile(User $user): SalaryProfile
    {
        return SalaryProfile::query()->create([
            'user_id' => $user->id,
            'base_salary' => 35000,
            'fixed_allowance' => 0,
            'labor_insurance_deduction' => 0,
            'health_insurance_deduction' => 0,
            'commission_enabled' => true,
            'is_active' => true,
        ]);
    }

    private function plan(User $admin): CommissionPlan
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

        return $plan;
    }

    private function validVehicle(User $agent): Vehicle
    {
        $vehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-06-15 12:00:00',
            'purchase_price' => 50000,
            'sold_price' => 100000,
            'purchase_agent_id' => $agent->id,
            'sales_agent_id' => $agent->id,
        ]);
        $this->entry($vehicle, 'expense', '購車付款', 50000);
        $this->entry($vehicle, 'income', '尾款收入', 100000);

        return $vehicle;
    }

    private function entry(Vehicle $vehicle, string $direction, string $category, int $amount): MoneyEntry
    {
        return MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'direction' => $direction,
            'category' => $category,
            'amount' => $amount,
            'approval_status' => MoneyEntry::APPROVAL_APPROVED,
            'source_type' => MoneyEntry::SOURCE_MANUAL,
        ]);
    }

    private function period(User $admin): SalaryPeriod
    {
        $plan = $this->plan($admin);

        return SalaryPeriod::query()->create([
            'period_month' => '2026-06-01',
            'commission_plan_id' => $plan->id,
            'status' => SalaryPeriod::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
    }

    /** @return array<int, string> */
    private function recursiveJsonKeys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];
        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            $keys = [...$keys, ...$this->recursiveJsonKeys($nested)];
        }

        return $keys;
    }

    /** @param array<int, string> $queries */
    private function eligibilityVehicleScanCount(array $queries): int
    {
        return collect($queries)->filter(
            fn (string $sql): bool => (str_contains($sql, ' from "vehicles"') || str_contains($sql, ' from `vehicles`'))
                && (str_contains($sql, '"status" = ?') || str_contains($sql, '`status` = ?'))
                && (str_contains($sql, '"sold_at" >= ?') || str_contains($sql, '`sold_at` >= ?')),
        )->count();
    }

    /** @param array<int, string> $queries */
    private function eligibilityMoneyScanCount(array $queries): int
    {
        return collect($queries)->filter(
            fn (string $sql): bool => (str_contains($sql, ' from "money_entries"') || str_contains($sql, ' from `money_entries`'))
                && (str_contains($sql, '"approval_status"') || str_contains($sql, '`approval_status`'))
                && (str_contains($sql, '"source_type"') || str_contains($sql, '`source_type`')),
        )->count();
    }
}
