<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $request->user()?->role;
        $canViewFinancials = in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER], true);

        $workOverview = [
            'preparation_pending_count' => $this->resource['work_overview']['preparation_pending_count'],
            'listing_pending_count' => $this->resource['work_overview']['listing_pending_count'],
            'delivery_pending_count' => $this->resource['work_overview']['delivery_pending_count'],
        ];

        if ($role === User::ROLE_ADMIN) {
            $workOverview['pending_money_entry_count'] = $this->resource['work_overview']['pending_money_entry_count'];
        }

        $businessOverview = [
            'inventory_count' => $this->resource['business_overview']['inventory_count'],
        ];

        $trends = [
            'sales_count' => $this->resource['trends']['sales_count'],
        ];

        if ($canViewFinancials) {
            $businessOverview = array_merge($businessOverview, [
                'cash_balance' => $this->resource['business_overview']['cash_balance'],
                'monthly_income' => $this->resource['business_overview']['monthly_income'],
                'monthly_expense' => $this->resource['business_overview']['monthly_expense'],
                'monthly_gross_profit' => $this->resource['business_overview']['monthly_gross_profit'],
                'monthly_sold_count' => $this->resource['business_overview']['monthly_sold_count'],
            ]);
            $trends['gross_profit'] = $this->resource['trends']['gross_profit'];
            $trends['cash_balance'] = $this->resource['trends']['cash_balance'];
        }

        $result = [
            'work_overview' => $workOverview,
            'business_overview' => $businessOverview,
            'trends' => $trends,
        ];

        // 第 4 部分完成前，保留舊 Dashboard 所需欄位；財務欄位仍只對白名單角色輸出。
        $result['vehicle_counts'] = $this->resource['vehicle_counts'];
        $result['monthly_sold_count'] = $this->resource['monthly_sold_count'];

        if ($canViewFinancials) {
            $result = array_merge($result, [
                'cash_balance' => $this->resource['cash_balance'],
                'bank_balance' => $this->resource['bank_balance'],
                'other_balance' => $this->resource['other_balance'],
                'total_funds' => $this->resource['total_funds'],
                'monthly_income' => $this->resource['monthly_income'],
                'monthly_expense' => $this->resource['monthly_expense'],
                'monthly_net_flow' => $this->resource['monthly_net_flow'],
            ]);
        }

        return $result;
    }
}
