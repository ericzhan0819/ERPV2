<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordHasBeenChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return new JsonResponse([
                'message' => '請先修改密碼',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
            ], 409);
        }

        return $next($request);
    }
}
