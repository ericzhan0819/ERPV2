<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canViewFinancials = $user?->canViewFinancials() ?? false;

        $workOverview = [
            'preparation_pending_count' => $this->resource['work_overview']['preparation_pending_count'],
            'listing_pending_count' => $this->resource['work_overview']['listing_pending_count'],
            'delivery_pending_count' => $this->resource['work_overview']['delivery_pending_count'],
        ];

        if ($user?->isAdmin() ?? false) {
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

        return [
            'work_overview' => $workOverview,
            'business_overview' => $businessOverview,
            'trends' => $trends,
        ];
    }
}
