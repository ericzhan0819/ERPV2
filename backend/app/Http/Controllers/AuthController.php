<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function login(LoginRequest $request): UserResource|JsonResponse
    {
        try {
            $user = $this->authService->login(
                $request->validated('email'),
                $request->validated('password'),
            );
        } catch (AuthenticationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new UserResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout();

        return response()->json(['message' => '已登出']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
