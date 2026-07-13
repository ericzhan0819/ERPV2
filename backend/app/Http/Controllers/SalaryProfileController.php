<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpsertSalaryProfileRequest;
use App\Http\Resources\SalaryProfileResource;
use App\Models\User;
use App\Services\SalaryProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalaryProfileController extends Controller
{
    public function __construct(private readonly SalaryProfileService $salaryProfileService) {}

    public function index(): AnonymousResourceCollection
    {
        return SalaryProfileResource::collection($this->salaryProfileService->listProfiles());
    }

    public function upsert(UpsertSalaryProfileRequest $request, User $user): JsonResponse
    {
        $profile = $this->salaryProfileService->upsertProfile(
            $request->user(),
            $user,
            $request->validated(),
        );

        return (new SalaryProfileResource($profile))->response()->setStatusCode(200);
    }
}
