<?php

namespace App\Http\Controllers;

use App\Http\Resources\DashboardSummaryResource;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService) {}

    public function summary(Request $request): JsonResponse
    {
        $summary = $this->dashboardService->summary();

        return response()->json(
            (new DashboardSummaryResource($summary))->resolve($request)
        );
    }
}
