<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService) {}

    public function summary(Request $request): JsonResponse
    {
        $summary = $this->dashboardService->summary();

        if ($request->user()?->isSales()) {
            $summary = [
                'vehicle_counts' => $summary['vehicle_counts'],
                'monthly_sold_count' => $summary['monthly_sold_count'],
            ];
        }

        return response()->json($summary);
    }
}
