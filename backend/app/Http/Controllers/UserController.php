<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection($this->userService->listUsers());
    }

    public function store(StoreUserRequest $request): UserResource
    {
        $user = $this->userService->createUser($request->validated());

        return new UserResource($user);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user = $this->userService->updateUser($user, $request->validated());

        return new UserResource($user);
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): UserResource
    {
        $user = $this->userService->setActive($request->user(), $user, $request->boolean('is_active'));

        return new UserResource($user);
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user): UserResource
    {
        $user = $this->userService->setRole($request->user(), $user, $request->validated('role'));

        return new UserResource($user);
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): JsonResponse
    {
        $this->userService->resetPassword($user, $request->validated('password'));

        return response()->json(['message' => '密碼已重設']);
    }

    public function destroy(User $user, Request $request): JsonResponse
    {
        $this->userService->deleteUser($request->user(), $user);

        return response()->json(['message' => '使用者已刪除']);
    }
}
