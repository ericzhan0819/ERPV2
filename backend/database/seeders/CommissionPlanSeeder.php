<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\CommissionPlanRules;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CommissionPlanSeeder extends Seeder
{
    private const PLAN_NAME = '2026 標準薪資方案';

    public function run(): void
    {
        $tiers = [
            ['min_sales_count' => 1, 'sales_bonus_bps' => 2000, 'sort_order' => 1],
            ['min_sales_count' => 3, 'sales_bonus_bps' => 3000, 'sort_order' => 2],
            ['min_sales_count' => 5, 'sales_bonus_bps' => 5000, 'sort_order' => 3],
        ];

        CommissionPlanRules::validate(4000, 2000, $tiers);

        DB::transaction(function () use ($tiers) {
            $adminId = User::query()
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->orderBy('id')
                ->value('id');

            if ($adminId === null) {
                throw new RuntimeException('建立初始獎金方案前必須先有 active admin');
            }

            $plan = DB::table('commission_plans')
                ->where('name', self::PLAN_NAME)
                ->lockForUpdate()
                ->first();
            $now = now();

            if ($plan === null) {
                $planId = DB::table('commission_plans')->insertGetId([
                    'name' => self::PLAN_NAME,
                    'effective_from' => '2026-01-01',
                    'company_reserve_bps' => 4000,
                    'purchase_bonus_bps' => 2000,
                    'is_active' => true,
                    'created_by' => $adminId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $planId = (int) $plan->id;
                $isReferenced = DB::table('salary_periods')->where('commission_plan_id', $planId)->exists();

                if ($isReferenced) {
                    $this->assertReferencedPlanIsStandard($planId, $plan, $tiers);

                    return;
                }

                DB::table('commission_plans')->where('id', $planId)->update([
                    'effective_from' => '2026-01-01',
                    'company_reserve_bps' => 4000,
                    'purchase_bonus_bps' => 2000,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
                DB::table('commission_plan_tiers')->where('commission_plan_id', $planId)->delete();
            }

            foreach ($tiers as $tier) {
                DB::table('commission_plan_tiers')->insert([
                    'commission_plan_id' => $planId,
                    ...$tier,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * @param  array<int, array{min_sales_count: int, sales_bonus_bps: int, sort_order: int}>  $tiers
     */
    private function assertReferencedPlanIsStandard(int $planId, object $plan, array $tiers): void
    {
        $storedTiers = DB::table('commission_plan_tiers')
            ->where('commission_plan_id', $planId)
            ->orderBy('sort_order')
            ->get(['min_sales_count', 'sales_bonus_bps', 'sort_order'])
            ->map(fn (object $tier) => [
                'min_sales_count' => (int) $tier->min_sales_count,
                'sales_bonus_bps' => (int) $tier->sales_bonus_bps,
                'sort_order' => (int) $tier->sort_order,
            ])->all();

        if ((string) $plan->effective_from !== '2026-01-01'
            || (int) $plan->company_reserve_bps !== 4000
            || (int) $plan->purchase_bonus_bps !== 2000
            || $storedTiers !== $tiers) {
            throw new RuntimeException('已被薪資月份引用的初始獎金方案與標準規則不一致，Seeder 不會覆寫歷史規則');
        }
    }
}
