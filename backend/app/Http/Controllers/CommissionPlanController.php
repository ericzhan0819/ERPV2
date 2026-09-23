<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommissionPlanRequest;
use App\Http\Resources\CommissionPlanResource;
use App\Models\CommissionPlan;
use App\Services\CommissionPlanService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CommissionPlanController extends Controller
{
    public function __construct(private readonly CommissionPlanService $commissionPlanService) {}

    public function index(): AnonymousResourceCollection
    {
        return CommissionPlanResource::collection($this->commissionPlanService->listPlans());
    }

    public function store(StoreCommissionPlanRequest $request): CommissionPlanResource
    {
        $plan = $this->commissionPlanService->createPlan($request->user(), $request->validated());

        return new CommissionPlanResource($plan);
    }

    public function show(CommissionPlan $commissionPlan): CommissionPlanResource
    {
        return new CommissionPlanResource($this->commissionPlanService->getPlan($commissionPlan));
    }
}
