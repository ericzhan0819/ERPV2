<?php

namespace App\Services;

use App\Exceptions\TooManyLoginAttemptsException;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class AuthService
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

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

        // IP 的總限制只計登入失敗次數，所以這裡只讀取是否超限，不會先占用一次額度。
        if (RateLimiter::tooManyAttempts($ipKey, $maxIpAttempts)) {
            throw new TooManyLoginAttemptsException(RateLimiter::availableIn($ipKey));
        }

        // 呼叫 Auth::attempt 前，先把帳號加 IP 與帳號本身的額度各記一次。
        // 這樣多個請求同時進來時，不會都先看到「尚未超限」而一起通過。
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

        try {
            $this->auditLogService->recordAuthentication('login', $user);
        } catch (Throwable $e) {
            // 無法留下登入稽核紀錄時，不可保留已登入的工作階段。
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            throw $e;
        }

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

    /**
     * 可重複安全呼叫：即使使用者已登出，或用戶端因未收到回應而重試，也不會出錯。
     */
    public function logout(): void
    {
        $user = Auth::guard('web')->user();

        try {
            if ($user instanceof User) {
                $this->auditLogService->recordAuthentication('logout', $user);
            }
        } finally {
            // 登出以安全為優先：就算稽核紀錄寫入失敗，也一定先讓登入工作階段失效。
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
            }

            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }
    }
}
