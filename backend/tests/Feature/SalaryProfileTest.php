<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CommissionPlan;
use App\Models\SalaryPeriod;
use App\Models\SalaryProfile;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Services\SalaryProfileService;
use Database\Seeders\CommissionPlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class SalaryProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_list_salary_profiles(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->sales()->create();

        $this->actingAs($admin, 'web')->putJson("/api/salary-profiles/{$employee->id}", $this->payload())
            ->assertOk()
            ->assertJsonPath('data.user.id', $employee->id)
            ->assertJsonPath('data.base_salary', 35000)
            ->assertJsonMissingPath('data.net_pay');

        $this->actingAs($admin, 'web')->getJson('/api/salary-profiles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.email', $employee->email);

        $updated = $this->payload(['base_salary' => 38000, 'commission_enabled' => false]);
        $this->actingAs($admin, 'web')->putJson("/api/salary-profiles/{$employee->id}", $updated)
            ->assertOk()
            ->assertJsonPath('data.base_salary', 38000)
            ->assertJsonPath('data.commission_enabled', false);

        $this->assertDatabaseHas('salary_profiles', [
            'user_id' => $employee->id,
            'base_salary' => 38000,
            'commission_enabled' => false,
        ]);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_manager_sales_and_unknown_roles_cannot_access_salary_profiles(string $role): void
    {
        $viewer = User::factory()->create(['role' => $role, 'is_admin' => false]);
        $employee = User::factory()->sales()->create();

        $this->actingAs($viewer, 'web')->getJson('/api/salary-profiles')->assertForbidden();
        $this->actingAs($viewer, 'web')->putJson("/api/salary-profiles/{$employee->id}", $this->payload())
            ->assertForbidden();

        $this->assertDatabaseMissing('salary_profiles', ['user_id' => $employee->id]);
    }

    public static function nonAdminRoles(): array
    {
        return [['manager'], ['sales'], ['unknown']];
    }

    public function test_salary_amounts_must_be_non_negative_integers_and_snapshots_are_prohibited(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->sales()->create();

        $this->actingAs($admin, 'web')->putJson("/api/salary-profiles/{$employee->id}", $this->payload([
            'base_salary' => -1,
            'fixed_allowance' => 1.5,
            'net_pay' => 999999,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['base_salary', 'fixed_allowance', 'net_pay']);
    }

    public function test_only_active_users_can_have_an_active_salary_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->sales()->create(['is_active' => false]);

        $this->actingAs($admin, 'web')->putJson("/api/salary-profiles/{$employee->id}", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->actingAs($admin, 'web')->putJson("/api/salary-profiles/{$employee->id}", $this->payload(['is_active' => false]))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    #[DataProvider('lockedPeriodStatuses')]
    public function test_updating_profile_does_not_change_locked_settlement_snapshot(string $status): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->sales()->create();
        $this->seed(CommissionPlanSeeder::class);
        $planId = (int) CommissionPlan::query()->value('id');
        $period = SalaryPeriod::query()->create([
            'period_month' => '2026-07-01',
            'commission_plan_id' => $planId,
            'status' => SalaryPeriod::STATUS_CONFIRMED,
            'created_by' => $admin->id,
            'confirmed_by' => $admin->id,
            'confirmed_at' => now(),
        ]);
        $settlement = SalarySettlement::query()->create([
            'salary_period_id' => $period->id,
            'user_id' => $employee->id,
            'base_salary_snapshot' => 35000,
        ]);
        if ($status === SalaryPeriod::STATUS_PAID) {
            $period->update([
                'status' => SalaryPeriod::STATUS_PAID,
                'paid_by' => $admin->id,
                'paid_at' => now(),
            ]);
        }

        $this->actingAs($admin, 'web')->putJson("/api/salary-profiles/{$employee->id}", $this->payload([
            'base_salary' => 50000,
        ]))->assertOk();

        $this->assertSame(35000, $settlement->fresh()->base_salary_snapshot);
    }

    public static function lockedPeriodStatuses(): array
    {
        return [[SalaryPeriod::STATUS_CONFIRMED], [SalaryPeriod::STATUS_PAID]];
    }

    public function test_salary_profile_audit_metadata_contains_no_salary_amount_values(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->sales()->create();

        $this->actingAs($admin, 'web')->putJson("/api/salary-profiles/{$employee->id}", $this->payload())
            ->assertOk();

        $log = AuditLog::query()->where('subject_type', 'salary_profile')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(['user_id', 'changed_fields'], array_keys($log->after_values));
        $serialized = json_encode($log->after_values, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('35000', $serialized);
        $this->assertStringNotContainsString('1200', $serialized);
        $this->assertStringNotContainsString('800', $serialized);
        $this->assertStringNotContainsString('600', $serialized);
    }

    public function test_user_with_salary_profile_is_gracefully_protected_from_deletion(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->sales()->create();
        $this->actingAs($admin, 'web')->putJson("/api/salary-profiles/{$employee->id}", $this->payload())
            ->assertOk();

        $this->actingAs($admin, 'web')->deleteJson("/api/users/{$employee->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');
        $this->assertDatabaseHas('users', ['id' => $employee->id]);
    }

    public function test_duplicate_key_recovery_replays_same_winner_and_rejects_different_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->sales()->create();
        $service = app(SalaryProfileService::class);
        $winner = $service->upsertProfile($admin, $employee, $this->payload());

        try {
            SalaryProfile::query()->create(array_merge(
                ['user_id' => $employee->id],
                $this->payload(),
            ));
            $this->fail('測試前置應觸發 salary_profiles.user_id unique violation');
        } catch (QueryException $duplicate) {
            $classifier = new ReflectionMethod($service, 'isSalaryProfileUserUniqueViolation');
            $this->assertTrue($classifier->invoke($service, $duplicate));

            $recover = new ReflectionMethod($service, 'replayRacedProfileUpsertAfterRollback');
            $replayed = $recover->invoke($service, $duplicate, $employee, $this->payload());
            $this->assertSame($winner->id, $replayed->id);

            try {
                $recover->invoke($service, $duplicate, $employee, $this->payload(['base_salary' => 36000]));
                $this->fail('不同 payload 應回傳 422 ValidationException');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('user', $exception->errors());
            }
        }

        $this->assertSame(1, SalaryProfile::query()->where('user_id', $employee->id)->count());
        $this->assertSame(1, AuditLog::query()->where('subject_type', 'salary_profile')->count());
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'base_salary' => 35000,
            'fixed_allowance' => 1200,
            'labor_insurance_deduction' => 800,
            'health_insurance_deduction' => 600,
            'commission_enabled' => true,
            'is_active' => true,
        ], $overrides);
    }
}
