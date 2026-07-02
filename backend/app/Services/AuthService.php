<?php

namespace App\Services;

use App\Exceptions\TooManyLoginAttemptsException;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthService
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    /**
     * @throws AuthenticationException
     * @throws TooManyLoginAttemptsException
     */
    public function login(string $email, string $password): User
    {
        $throttleKey = $this->throttleKey($email);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            throw new TooManyLoginAttemptsException(RateLimiter::availableIn($throttleKey));
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);

            throw new AuthenticationException('帳號或密碼錯誤');
        }

        RateLimiter::clear($throttleKey);

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();

            throw new AuthenticationException('此帳號已被停用');
        }

        request()->session()->regenerate();

        return $user;
    }

    private function throttleKey(string $email): string
    {
        return Str::lower(trim($email)).'|'.request()->ip();
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }
}
