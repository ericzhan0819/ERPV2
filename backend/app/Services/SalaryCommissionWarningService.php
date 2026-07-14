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
            ->get(['id', 'name'])
            ->keyBy('id');

        // 與薪資草稿建立 settlement 時同一個 scope，避免「啟用中的薪資設定」在兩邊各寫一份而漂移。
        $activeProfiles = SalaryProfile::query()
            ->settlementActive()
            ->whereIn('user_id', $agentIds)
            ->get(['user_id', 'commission_enabled'])
            ->keyBy('user_id');
        $warnings = [];

        foreach ($eligibleResults as $result) {
            foreach ([
                'purchase' => ['agent_id' => $result['vehicle']->purchase_agent_id, 'label' => '收車人'],
                'sales' => ['agent_id' => $result['vehicle']->sales_agent_id, 'label' => '賣車人'],
            ] as $role => $attribution) {
                $agentId = (int) $attribution['agent_id'];
                $profile = $activeProfiles->get($agentId);

                if ($profile?->commission_enabled) {
                    continue;
                }

                $user = $users->get($agentId);
                $agentName = $user?->name ?? "使用者 #{$agentId}";
                $reason = $profile ? '獎金設定未啟用' : '沒有啟用中的薪資設定';
                $warnings[] = [
                    'vehicle_id' => (int) $result['vehicle_id'],
                    'stock_no' => (string) $result['stock_no'],
                    'code' => $profile ? 'commission_disabled' : 'active_salary_profile_missing',
                    'role' => $role,
                    'agent_id' => $agentId,
                    'agent_name' => $user?->name,
                    'message' => "{$attribution['label']}「{$agentName}」{$reason}，本車{$attribution['label']}獎金不會發給員工，金額將歸入公司剩餘分配額。",
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
