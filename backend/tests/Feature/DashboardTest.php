<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-21 12:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_work_overview_and_inventory_use_the_formal_vehicle_definitions(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);

        Vehicle::factory()->create(['status' => 'preparing', 'is_preparation_completed' => false]);
        Vehicle::factory()->count(2)->create(['status' => 'preparing', 'is_preparation_completed' => true]);
        Vehicle::factory()->count(3)->create(['status' => 'listed', 'is_preparation_completed' => true]);
        Vehicle::factory()->count(4)->create(['status' => 'reserved', 'is_preparation_completed' => true]);
        Vehicle::factory()->create(['status' => 'sold', 'sold_at' => '2026-07-10 10:00:00']);
        Vehicle::factory()->create(['status' => 'cancelled']);

        MoneyEntry::factory()->count(2)->create(['approval_status' => MoneyEntry::APPROVAL_PENDING]);
        MoneyEntry::factory()->create(['approval_status' => MoneyEntry::APPROVAL_REJECTED]);

        $response = $this->actingAs($admin, 'web')->getJson('/api/dashboard/summary');

        $response->assertOk();
        $response->assertJsonPath('work_overview.preparation_pending_count', 1);
        $response->assertJsonPath('work_overview.listing_pending_count', 2);
        $response->assertJsonPath('work_overview.delivery_pending_count', 4);
        $response->assertJsonPath('work_overview.pending_money_entry_count', 2);
        $response->assertJsonPath('business_overview.inventory_count', 10);
    }

    public function test_monthly_financials_and_gross_profit_only_include_approved_entries(): void
    {
        $manager = User::factory()->manager()->create(['is_active' => true]);
        $cash = CashAccount::factory()->create(['type' => 'cash', 'opening_balance' => 1000]);
        $soldThisMonth = Vehicle::factory()->create(['status' => 'sold', 'sold_at' => '2026-07-01 00:00:00']);
        $soldLastMonth = Vehicle::factory()->create(['status' => 'sold', 'sold_at' => '2026-06-30 23:59:59']);

        $this->moneyEntry($cash, $soldThisMonth, 'income', 100000, '2026-07-02');
        $this->moneyEntry($cash, $soldThisMonth, 'expense', 30000, '2026-06-15');
        $this->moneyEntry($cash, $soldThisMonth, 'income', 900000, '2026-07-03', MoneyEntry::APPROVAL_PENDING);
        $this->moneyEntry($cash, $soldThisMonth, 'expense', 800000, '2026-07-03', MoneyEntry::APPROVAL_REJECTED);
        $this->moneyEntry($cash, $soldLastMonth, 'income', 50000, '2026-07-04');

        $response = $this->actingAs($manager, 'web')->getJson('/api/dashboard/summary');

        $response->assertOk();
        $response->assertJsonPath('business_overview.cash_balance', 121000);
        $response->assertJsonPath('business_overview.monthly_income', 150000);
        $response->assertJsonPath('business_overview.monthly_expense', 0);
        $response->assertJsonPath('business_overview.monthly_gross_profit', 70000);
        $response->assertJsonPath('business_overview.monthly_sold_count', 1);
    }

    public function test_trends_have_thirty_taipei_dates_with_zero_filled_sales_and_approved_only_profit(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cash = CashAccount::factory()->create(['type' => 'cash']);
        $firstDay = Vehicle::factory()->create(['status' => 'sold', 'sold_at' => '2026-06-22 00:00:00']);
        $today = Vehicle::factory()->create(['status' => 'sold', 'sold_at' => '2026-07-21 23:59:59']);
        $outside = Vehicle::factory()->create(['status' => 'sold', 'sold_at' => '2026-06-21 23:59:59']);

        $this->moneyEntry($cash, $firstDay, 'income', 50000, '2026-06-01');
        $this->moneyEntry($cash, $firstDay, 'expense', 12000, '2026-06-02');
        $this->moneyEntry($cash, $firstDay, 'expense', 99999, '2026-06-02', MoneyEntry::APPROVAL_PENDING);
        $this->moneyEntry($cash, $today, 'income', 30000, '2026-07-21');
        $this->moneyEntry($cash, $outside, 'income', 77777, '2026-06-01');

        $response = $this->actingAs($admin, 'web')->getJson('/api/dashboard/summary');

        $response->assertOk();
        $sales = $response->json('trends.sales_count');
        $profit = $response->json('trends.gross_profit');

        $this->assertCount(30, $sales);
        $this->assertCount(30, $profit);
        $this->assertSame('2026-06-22', $sales[0]['date']);
        $this->assertSame('2026-07-21', $sales[29]['date']);
        $this->assertSame(1, $sales[0]['count']);
        $this->assertSame(1, $sales[29]['count']);
        $this->assertSame(0, $sales[1]['count']);
        $this->assertSame(38000, $profit[0]['amount']);
        $this->assertSame(30000, $profit[29]['amount']);
    }

    public function test_cash_trend_returns_daily_ending_balance_and_carries_days_without_activity(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cash = CashAccount::factory()->create(['type' => 'cash', 'opening_balance' => 1000]);
        $bank = CashAccount::factory()->create(['type' => 'bank', 'opening_balance' => 500000]);

        $this->moneyEntry($cash, null, 'income', 200, '2026-06-01');
        $this->moneyEntry($cash, null, 'income', 500, '2026-06-22');
        $this->moneyEntry($cash, null, 'expense', 300, '2026-06-24');
        $this->moneyEntry($cash, null, 'income', 9000, '2026-06-23', MoneyEntry::APPROVAL_PENDING);
        $this->moneyEntry($bank, null, 'income', 8000, '2026-06-23');

        $response = $this->actingAs($admin, 'web')->getJson('/api/dashboard/summary');

        $response->assertOk();
        $trend = $response->json('trends.cash_balance');

        $this->assertCount(30, $trend);
        $this->assertSame(['date' => '2026-06-22', 'balance' => 1700], $trend[0]);
        $this->assertSame(['date' => '2026-06-23', 'balance' => 1700], $trend[1]);
        $this->assertSame(['date' => '2026-06-24', 'balance' => 1400], $trend[2]);
        $this->assertSame(1400, $trend[29]['balance']);
    }

    public function test_cash_kpi_preserves_ledger_scope_while_trend_and_monthly_totals_use_their_date_windows(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cash = CashAccount::factory()->create(['type' => 'cash', 'opening_balance' => 1000]);

        $this->moneyEntry($cash, null, 'income', 200, '2026-07-25');
        $this->moneyEntry($cash, null, 'income', 999999, '2026-08-05');

        $response = $this->actingAs($admin, 'web')->getJson('/api/dashboard/summary');

        $response->assertOk();
        // 正式餘額沿用 Cash Account 既有帳面口徑，包含所有已核准收支；趨勢只到今天。
        $response->assertJsonPath('business_overview.cash_balance', 1001199);
        $response->assertJsonPath('business_overview.monthly_income', 200);
        $response->assertJsonPath('trends.cash_balance.29.date', '2026-07-21');
        $response->assertJsonPath('trends.cash_balance.29.balance', 1000);
    }

    public function test_monthly_sales_and_profit_include_future_sold_at_while_trends_stop_at_today(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $cash = CashAccount::factory()->create(['type' => 'cash']);
        $futureSale = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-07-25 12:00:00',
        ]);
        $this->moneyEntry($cash, $futureSale, 'income', 500000, '2026-07-21');

        $response = $this->actingAs($admin, 'web')->getJson('/api/dashboard/summary');

        $response->assertOk();
        $response->assertJsonPath('business_overview.monthly_sold_count', 1);
        $response->assertJsonPath('business_overview.monthly_gross_profit', 500000);
        $this->assertSame(0, collect($response->json('trends.sales_count'))->sum('count'));
        $this->assertSame(0, collect($response->json('trends.gross_profit'))->sum('amount'));
    }

    public function test_role_resource_masks_pending_and_financial_fields_from_raw_json(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);
        $manager = User::factory()->manager()->create(['is_active' => true]);
        $sales = User::factory()->sales()->create(['is_active' => true]);
        $unknown = User::factory()->create(['is_active' => true, 'role' => 'future_role']);

        foreach ([$admin, $manager] as $user) {
            $this->app['auth']->forgetGuards();
            $response = $this->actingAs($user, 'web')->getJson('/api/dashboard/summary');

            $response->assertOk();
            $this->assertSame(['work_overview', 'business_overview', 'trends'], array_keys($response->json()));
            $response->assertJsonStructure([
                'work_overview' => ['preparation_pending_count', 'listing_pending_count', 'delivery_pending_count'],
                'business_overview' => [
                    'inventory_count', 'cash_balance', 'monthly_income', 'monthly_expense',
                    'monthly_gross_profit', 'monthly_sold_count',
                ],
                'trends' => ['sales_count', 'gross_profit', 'cash_balance'],
            ]);
        }

        $this->app['auth']->forgetGuards();
        $managerJson = $this->actingAs($manager, 'web')
            ->getJson('/api/dashboard/summary')
            ->assertOk()
            ->json();
        $this->assertArrayNotHasKey('pending_money_entry_count', $managerJson['work_overview']);

        foreach ([$sales, $unknown] as $user) {
            $this->app['auth']->forgetGuards();
            $response = $this->actingAs($user, 'web')->getJson('/api/dashboard/summary');

            $response->assertOk();
            $json = $response->json();
            $response->assertJsonStructure([
                'work_overview' => ['preparation_pending_count', 'listing_pending_count', 'delivery_pending_count'],
                'business_overview' => ['inventory_count'],
                'trends' => ['sales_count'],
            ]);
            $this->assertArrayNotHasKey('pending_money_entry_count', $json['work_overview']);
            $this->assertArrayNotHasKey('cash_balance', $json['business_overview']);
            $this->assertArrayNotHasKey('monthly_income', $json['business_overview']);
            $this->assertArrayNotHasKey('monthly_expense', $json['business_overview']);
            $this->assertArrayNotHasKey('monthly_gross_profit', $json['business_overview']);
            $this->assertArrayNotHasKey('monthly_sold_count', $json['business_overview']);
            $this->assertArrayNotHasKey('gross_profit', $json['trends']);
            $this->assertArrayNotHasKey('cash_balance', $json['trends']);
            $this->assertSame(['work_overview', 'business_overview', 'trends'], array_keys($json));
        }
    }

    private function moneyEntry(
        CashAccount $account,
        ?Vehicle $vehicle,
        string $direction,
        int $amount,
        string $entryDate,
        string $approvalStatus = MoneyEntry::APPROVAL_APPROVED,
    ): MoneyEntry {
        return MoneyEntry::factory()->create([
            'cash_account_id' => $account->id,
            'vehicle_id' => $vehicle?->id,
            'entry_date' => $entryDate,
            'direction' => $direction,
            'amount' => $amount,
            'approval_status' => $approvalStatus,
        ]);
    }
}
