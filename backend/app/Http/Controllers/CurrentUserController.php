<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCurrentUserPasswordRequest;
use App\Http\Requests\UpdateCurrentUserProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\UserService;

class CurrentUserController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    public function updateProfile(UpdateCurrentUserProfileRequest $request): UserResource
    {
        $user = $this->userService->updateCurrentProfile($request->user(), $request->validated());

        return new UserResource($user);
    }

    public function updatePassword(UpdateCurrentUserPasswordRequest $request): UserResource
    {
        $user = $this->userService->updateCurrentPassword(
            $request->user(),
            $request->validated('password'),
        );
        $request->session()->regenerate();

        return new UserResource($user);
    }
}
