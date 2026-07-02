<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            $guard = auth('web');
            $guard->logout();
            $request->session()->invalidate();

            return response()->json(['message' => '此帳號已被停用'], 403);
        }

        return $next($request);
    }
}
