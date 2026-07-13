<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CommissionPlan;
use App\Models\SalaryPeriod;
use App\Models\User;
use App\Services\CommissionPlanService;
use Database\Seeders\CommissionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommissionPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_and_view_a_versioned_plan(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'web')->postJson('/api/commission-plans', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', '2027 新方案')
            ->assertJsonPath('data.created_by.id', $admin->id)
            ->assertJsonPath('data.is_used', false)
            ->assertJsonCount(3, 'data.tiers')
            ->assertJsonPath('data.tiers.2.sort_order', 3);

        $planId = $response->json('data.id');
        $this->actingAs($admin, 'web')->getJson('/api/commission-plans')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'web')->getJson("/api/commission-plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.effective_from', '2027-01-01');
    }

    #[DataProvider('nonAdminRoles')]
    public function test_manager_sales_and_unknown_roles_cannot_access_commission_plans(string $role): void
    {
        $viewer = User::factory()->create(['role' => $role, 'is_admin' => false]);

        $this->actingAs($viewer, 'web')->getJson('/api/commission-plans')->assertForbidden();
        $this->actingAs($viewer, 'web')->postJson('/api/commission-plans', $this->payload())->assertForbidden();

        $this->assertDatabaseMissing('commission_plans', ['name' => '2027 新方案']);
    }

    public static function nonAdminRoles(): array
    {
        return [['manager'], ['sales'], ['unknown']];
    }

    #[DataProvider('invalidTierPayloads')]
    public function test_tiers_must_be_complete_ordered_and_not_overallocated(array $tiers): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')->postJson('/api/commission-plans', $this->payload(['tiers' => $tiers]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tiers');
    }

    public static function invalidTierPayloads(): array
    {
        return [
            'empty' => [[]],
            'not starting at one' => [[['min_sales_count' => 2, 'sales_bonus_bps' => 2000]]],
            'not increasing' => [[
                ['min_sales_count' => 1, 'sales_bonus_bps' => 2000],
                ['min_sales_count' => 5, 'sales_bonus_bps' => 3000],
                ['min_sales_count' => 3, 'sales_bonus_bps' => 5000],
            ]],
            'overallocated' => [[['min_sales_count' => 1, 'sales_bonus_bps' => 9000]]],
        ];
    }

    public function test_effective_plan_selection_uses_latest_active_date_then_latest_id(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(CommissionPlanService::class);
        $older = $service->createPlan($admin, $this->payload(['name' => '舊方案', 'effective_from' => '2026-01-01']));
        $sameDateOlderId = $service->createPlan($admin, $this->payload(['name' => '同日方案 A', 'effective_from' => '2026-06-01']));
        $sameDateNewestId = $service->createPlan($admin, $this->payload(['name' => '同日方案 B', 'effective_from' => '2026-06-01']));
        $service->createPlan($admin, $this->payload(['name' => '停用方案', 'effective_from' => '2026-07-01', 'is_active' => false]));
        $service->createPlan($admin, $this->payload(['name' => '未來方案', 'effective_from' => '2027-01-01']));

        $this->assertSame($sameDateNewestId->id, $service->findEffectiveForMonth('2026-07-01')?->id);
        $this->assertNotSame($sameDateOlderId->id, $service->findEffectiveForMonth('2026-07-01')?->id);
        $this->assertSame($older->id, $service->findEffectiveForMonth('2026-03-01')?->id);
        $this->assertNull($service->findEffectiveForMonth('2025-12-01'));
    }

    public function test_used_plan_has_no_update_or_delete_api_and_database_remains_immutable(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seed(CommissionPlanSeeder::class);
        $plan = CommissionPlan::query()->firstOrFail();

        SalaryPeriod::query()->create([
            'period_month' => '2026-07-01',
            'commission_plan_id' => $plan->id,
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin, 'web')->patchJson("/api/commission-plans/{$plan->id}", [
            'purchase_bonus_bps' => 2500,
        ])->assertMethodNotAllowed();
        $this->actingAs($admin, 'web')->deleteJson("/api/commission-plans/{$plan->id}")
            ->assertMethodNotAllowed();

        $this->assertSame(2000, $plan->fresh()->purchase_bonus_bps);
    }

    public function test_plan_creation_writes_minimal_audit_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'web')->postJson('/api/commission-plans', $this->payload())->assertCreated();

        $log = AuditLog::query()->where('subject_type', 'commission_plan')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(['effective_from', 'is_active', 'tier_count'], array_keys($log->after_values));
    }

    public function test_plan_creator_is_gracefully_protected_from_deletion(): void
    {
        $owner = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $this->actingAs($owner, 'web')->postJson('/api/commission-plans', $this->payload())->assertCreated();

        $this->actingAs($otherAdmin, 'web')->deleteJson("/api/users/{$owner->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_service_duplicate_name_is_converted_to_validation_error_after_fresh_winner_read(): void
    {
        $admin = User::factory()->admin()->create();
        $service = app(CommissionPlanService::class);
        $service->createPlan($admin, $this->payload());

        try {
            $service->createPlan($admin, $this->payload());
            $this->fail('重名方案應回傳 ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name', $exception->errors());
        }

        $this->assertSame(1, CommissionPlan::query()->where('name', '2027 新方案')->count());
        $this->assertSame(3, CommissionPlan::query()->where('name', '2027 新方案')->firstOrFail()->tiers()->count());
        $this->assertSame(1, AuditLog::query()->where('subject_type', 'commission_plan')->count());
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => '2027 新方案',
            'effective_from' => '2027-01-01',
            'company_reserve_bps' => 4000,
            'purchase_bonus_bps' => 2000,
            'is_active' => true,
            'tiers' => [
                ['min_sales_count' => 1, 'sales_bonus_bps' => 2000],
                ['min_sales_count' => 3, 'sales_bonus_bps' => 3000],
                ['min_sales_count' => 5, 'sales_bonus_bps' => 5000],
            ],
        ], $overrides);
    }
}
