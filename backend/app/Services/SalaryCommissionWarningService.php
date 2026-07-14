<?php

namespace App\Services;

use App\Models\SalaryProfile;
use App\Models\User;

final class SalaryCommissionWarningService
{
    /**
     * @param  array<int, array<string, mixed>>  $vehicleResults
     * @return array<int, array<string, mixed>>
     */
    public function forVehicleResults(array $vehicleResults): array
    {
        $eligibleResults = collect($vehicleResults)
            ->filter(fn (array $result): bool => $result['eligible'] && $result['gross_profit'] > 0)
            ->values();
        $agentIds = $eligibleResults
            ->flatMap(fn (array $result): array => [
                $result['vehicle']->purchase_agent_id,
                $result['vehicle']->sales_agent_id,
            ])
            ->filter()
            ->unique()
            ->values();

        if ($agentIds->isEmpty()) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', $agentIds)
            ->get(['id', 'name', 'is_active'])
            ->keyBy('id');
        $profiles = SalaryProfile::query()
            ->whereIn('user_id', $agentIds)
            ->get(['user_id', 'is_active', 'commission_enabled'])
            ->keyBy('user_id');
        $warnings = [];

        foreach ($eligibleResults as $result) {
            foreach ([
                'purchase' => ['agent_id' => $result['vehicle']->purchase_agent_id, 'label' => '收車人'],
                'sales' => ['agent_id' => $result['vehicle']->sales_agent_id, 'label' => '賣車人'],
            ] as $role => $attribution) {
                $agentId = (int) $attribution['agent_id'];
                $user = $users->get($agentId);
                $profile = $profiles->get($agentId);
                $hasActiveProfile = $user?->is_active && $profile?->is_active;
                $commissionEnabled = $hasActiveProfile && $profile->commission_enabled;

                if ($commissionEnabled) {
                    continue;
                }

                $reason = $hasActiveProfile ? '獎金設定未啟用' : '沒有啟用中的薪資設定';
                $warnings[] = [
                    'vehicle_id' => (int) $result['vehicle_id'],
                    'stock_no' => (string) $result['stock_no'],
                    'code' => $hasActiveProfile ? 'commission_disabled' : 'active_salary_profile_missing',
                    'role' => $role,
                    'agent_id' => $agentId,
                    'agent_name' => $user?->name,
                    'message' => "{$attribution['label']}「{$user?->name}」{$reason}，本車{$attribution['label']}獎金不會發給員工，金額將歸入公司剩餘分配額。",
                    'correction' => [
                        'label' => '前往薪資設定',
                        'action' => 'salary_profile',
                    ],
                ];
            }
        }

        return $warnings;
    }
}
