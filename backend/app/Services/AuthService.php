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
    private const MAX_EMAIL_IP_ATTEMPTS = 5;

    private const EMAIL_IP_DECAY_SECONDS = 60;

    private const MAX_ACCOUNT_ATTEMPTS = 10;

    private const ACCOUNT_DECAY_SECONDS = 900;

    private const MAX_IP_ATTEMPTS = 30;

    private const IP_DECAY_SECONDS = 60;

    /**
     * @throws AuthenticationException
     * @throws TooManyLoginAttemptsException
     */
    public function login(string $email, string $password): User
    {
        $limiters = $this->limiters($email);

        foreach ($limiters as [$key, $maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw new TooManyLoginAttemptsException(RateLimiter::availableIn($key));
            }
        }

        // Reserve the attempt for every limiter before calling Auth::attempt so a
        // synchronized burst cannot all read "not blocked yet" and slip through.
        foreach ($limiters as [$key, , $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            throw new AuthenticationException('帳號或密碼錯誤');
        }

        RateLimiter::clear($limiters['email_ip'][0]);
        RateLimiter::clear($limiters['account'][0]);

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();

            throw new AuthenticationException('此帳號已被停用');
        }

        request()->session()->regenerate();

        return $user;
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    private function limiters(string $email): array
    {
        $normalizedEmail = Str::lower(trim($email));
        $ip = (string) request()->ip();

        return [
            'email_ip' => ['login:email_ip:'.$normalizedEmail.'|'.$ip, self::MAX_EMAIL_IP_ATTEMPTS, self::EMAIL_IP_DECAY_SECONDS],
            'account' => ['login:account:'.$normalizedEmail, self::MAX_ACCOUNT_ATTEMPTS, self::ACCOUNT_DECAY_SECONDS],
            'ip' => ['login:ip:'.$ip, self::MAX_IP_ATTEMPTS, self::IP_DECAY_SECONDS],
        ];
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }
}
