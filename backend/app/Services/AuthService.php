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
        [$ipKey, $maxIpAttempts, $ipDecaySeconds] = $limiters['ip'];

        // The IP-wide limiter only ever counts failed logins, so it is safe to
        // admission-check it read-only here without reserving an attempt.
        if (RateLimiter::tooManyAttempts($ipKey, $maxIpAttempts)) {
            throw new TooManyLoginAttemptsException(RateLimiter::availableIn($ipKey));
        }

        // Reserve (hit-then-admit) the email+IP and account attempts before calling
        // Auth::attempt, so concurrent requests can't all read "under the limit"
        // and slip through before any of them records a hit.
        foreach (['email_ip', 'account'] as $name) {
            [$key, $maxAttempts, $decaySeconds] = $limiters[$name];

            if (RateLimiter::hit($key, $decaySeconds) > $maxAttempts) {
                throw new TooManyLoginAttemptsException(RateLimiter::availableIn($key));
            }
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            RateLimiter::hit($ipKey, $ipDecaySeconds);

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
