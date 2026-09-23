<?php

namespace Tests\Feature;

use App\Http\Resources\MoneyEntryResource;
use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalaryMoneyEntryProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_source_constant_is_supported_and_general_crud_cannot_update_or_delete_it(): void
    {
        $admin = User::factory()->admin()->create();
        $account = CashAccount::factory()->create();
        $entry = MoneyEntry::factory()->create([
            'cash_account_id' => $account->id,
            'direction' => 'expense',
            'category' => '薪資 / 佣金',
            'amount' => 50000,
            'source_type' => MoneyEntry::SOURCE_SALARY_SETTLEMENT,
            'approval_status' => MoneyEntry::APPROVAL_APPROVED,
            'counterparty_name' => '受保護員工',
            'created_by' => $admin->id,
        ]);

        $this->assertContains(MoneyEntry::SOURCE_SALARY_SETTLEMENT, MoneyEntry::SOURCE_TYPES);

        $this->actingAs($admin, 'web')->patchJson("/api/money-entries/{$entry->id}", [
            'entry_date' => '2026-07-13',
            'direction' => 'expense',
            'category' => '薪資 / 佣金',
            'amount' => 1,
            'cash_account_id' => $account->id,
        ])->assertUnprocessable();

        $this->actingAs($admin, 'web')->deleteJson("/api/money-entries/{$entry->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('money_entries', [
            'id' => $entry->id,
            'amount' => 50000,
            'source_type' => MoneyEntry::SOURCE_SALARY_SETTLEMENT,
        ]);
    }

    public function test_salary_source_cannot_enter_general_approval_flow(): void
    {
        $admin = User::factory()->admin()->create();
        $entry = MoneyEntry::factory()->create([
            'source_type' => MoneyEntry::SOURCE_SALARY_SETTLEMENT,
            'direction' => 'expense',
            'category' => '薪資 / 佣金',
            'approval_status' => MoneyEntry::APPROVAL_APPROVED,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin, 'web')
            ->patchJson("/api/money-entries/{$entry->id}/approve")
            ->assertUnprocessable();
        $this->actingAs($admin, 'web')
            ->patchJson("/api/money-entries/{$entry->id}/reject")
            ->assertUnprocessable();
    }

    public function test_salary_source_requires_admin_and_direct_approved_contract(): void
    {
        $manager = User::factory()->manager()->create();
        $account = CashAccount::factory()->create();

        $this->expectException(QueryException::class);
        MoneyEntry::factory()->create([
            'cash_account_id' => $account->id,
            'direction' => 'expense',
            'category' => '薪資 / 佣金',
            'source_type' => MoneyEntry::SOURCE_SALARY_SETTLEMENT,
            'approval_status' => MoneyEntry::APPROVAL_PENDING,
            'created_by' => $manager->id,
        ]);
    }

    public function test_salary_source_is_immutable_even_when_bypassing_general_crud(): void
    {
        $admin = User::factory()->admin()->create();
        $entry = MoneyEntry::factory()->create([
            'direction' => 'expense',
            'category' => '薪資 / 佣金',
            'source_type' => MoneyEntry::SOURCE_SALARY_SETTLEMENT,
            'approval_status' => MoneyEntry::APPROVAL_APPROVED,
            'created_by' => $admin->id,
        ]);

        try {
            MoneyEntry::query()->whereKey($entry->id)->update(['source_type' => MoneyEntry::SOURCE_MANUAL]);
            $this->fail('salary settlement source 不得被直接改寫');
        } catch (QueryException) {
            $this->assertDatabaseHas('money_entries', [
                'id' => $entry->id,
                'source_type' => MoneyEntry::SOURCE_SALARY_SETTLEMENT,
            ]);
        }

        $this->expectException(QueryException::class);
        MoneyEntry::query()->whereKey($entry->id)->delete();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_manager_and_sales_cannot_list_or_enumerate_salary_entries(string $role): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['role' => $role]);
        $salaryEntry = MoneyEntry::factory()->create([
            'direction' => 'expense',
            'category' => '薪資 / 佣金',
            'amount' => 50000,
            'source_type' => MoneyEntry::SOURCE_SALARY_SETTLEMENT,
            'counterparty_name' => '受保護員工',
            'description' => '2026-07 薪資',
            'created_by' => $admin->id,
        ]);

        $list = $this->actingAs($user, 'web')->getJson('/api/money-entries')->assertOk();
        $this->assertNotContains($salaryEntry->id, collect($list->json('data'))->pluck('id')->all());

        $this->actingAs($user, 'web')
            ->getJson("/api/money-entries/{$salaryEntry->id}")
            ->assertForbidden();
    }

    public static function nonAdminRoles(): array
    {
        return [
            'manager' => [User::ROLE_MANAGER],
            'sales' => [User::ROLE_SALES],
        ];
    }

    public function test_admin_can_list_salary_entries(): void
    {
        $admin = User::factory()->admin()->create();
        $salaryEntry = MoneyEntry::factory()->create([
            'direction' => 'expense',
            'category' => '薪資 / 佣金',
            'amount' => 50000,
            'source_type' => MoneyEntry::SOURCE_SALARY_SETTLEMENT,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin, 'web')->getJson('/api/money-entries')
            ->assertJsonFragment(['id' => $salaryEntry->id, 'amount' => 50000]);
    }

    public function test_resource_defense_in_depth_omits_salary_details_for_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $account = CashAccount::factory()->create();
        $entry = MoneyEntry::factory()->create([
            'cash_account_id' => $account->id,
            'direction' => 'expense',
            'category' => '薪資 / 佣金',
            'amount' => 50000,
            'source_type' => MoneyEntry::SOURCE_SALARY_SETTLEMENT,
            'counterparty_name' => '受保護員工',
            'description' => '2026-07 薪資',
            'created_by' => $admin->id,
        ])->load('cashAccount');
        $request = Request::create('/api/money-entries/'.$entry->id);
        $request->setUserResolver(fn () => $manager);
        $json = (new MoneyEntryResource($entry))->toResponse($request)->getData(true)['data'];

        foreach (['amount', 'cash_account_id', 'cash_account', 'counterparty_name', 'description', 'approval_status', 'approved_by', 'approved_at'] as $key) {
            $this->assertArrayNotHasKey($key, $json);
        }
    }
}
