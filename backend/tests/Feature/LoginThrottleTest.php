<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAllLimiters();
    }

    protected function tearDown(): void
    {
        $this->clearAllLimiters();

        parent::tearDown();
    }

    public function test_same_email_and_ip_is_blocked_with_429_after_exceeding_limit(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->loginAs('admin@example.com', 'wrong-password')->assertStatus(422);
        }

        $this->loginAs('admin@example.com', 'wrong-password')->assertStatus(429);
    }

    public function test_login_still_returns_normal_failure_response_below_limit(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        for ($i = 0; $i < 4; $i++) {
            $this->loginAs('admin@example.com', 'wrong-password')->assertStatus(422);
        }
    }

    public function test_same_email_rotating_ip_is_blocked_by_account_limiter(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        // 10 failures spread across 10 different IPs stays under the 5/IP limiter
        // but must trip the 10/15min account-only limiter on the 11th attempt.
        for ($i = 0; $i < 10; $i++) {
            $this->loginAs('admin@example.com', 'wrong-password', "10.0.0.$i")->assertStatus(422);
        }

        $this->loginAs('admin@example.com', 'wrong-password', '10.0.0.99')->assertStatus(429);
    }

    public function test_same_ip_rotating_email_failures_are_blocked_by_ip_wide_limiter(): void
    {
        User::factory()->create(['email' => 'victim@example.com']);

        // 30 failures against 30 different (mostly non-existent) accounts from the
        // same IP must trip the 30/60s IP-wide limiter on the 31st attempt.
        for ($i = 0; $i < 30; $i++) {
            $this->loginAs("attacker{$i}@example.com", 'wrong-password', '203.0.113.5')->assertStatus(422);
        }

        $this->loginAs('victim@example.com', 'wrong-password', '203.0.113.5')->assertStatus(429);
    }

    public function test_more_than_thirty_successful_logins_from_one_ip_are_not_blocked(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        // Successful logins must never hit the IP-wide limiter (it only counts
        // failures), so 31 consecutive successes from the same IP must all
        // succeed instead of tripping the 30/60s IP-wide quota.
        for ($i = 0; $i < 31; $i++) {
            $this->loginAs('admin@example.com', 'correct-password', '198.51.100.10')->assertSuccessful();

            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
                ->withHeaders(['Referer' => 'http://localhost'])
                ->postJson('/api/logout')
                ->assertSuccessful();
        }
    }

    public function test_blocked_request_does_not_attempt_authentication_again(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->loginAs('admin@example.com', 'wrong-password')->assertStatus(422);
        }

        // Even with the correct password, a blocked email+IP pair must be
        // rejected with 429 instead of reaching Auth::attempt and logging in.
        $this->loginAs('admin@example.com', 'correct-password')->assertStatus(429);

        $this->assertGuest();
    }

    public function test_successful_login_clears_email_ip_and_account_limiters(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->loginAs('admin@example.com', 'wrong-password')->assertStatus(422);
        }

        $this->loginAs('admin@example.com', 'correct-password')->assertSuccessful();

        // Previous failures against this email+IP must not carry over after a
        // successful login clears the email_ip and account limiters.
        $this->loginAs('admin@example.com', 'wrong-password')->assertStatus(422);
    }

    private function loginAs(string $email, string $password, string $ip = '127.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['Referer' => 'http://localhost'])
            ->postJson('/api/login', [
                'email' => $email,
                'password' => $password,
            ]);
    }

    private function clearAllLimiters(): void
    {
        RateLimiter::clear('login:email_ip:admin@example.com|127.0.0.1');
        RateLimiter::clear('login:account:admin@example.com');
        RateLimiter::clear('login:ip:127.0.0.1');
        RateLimiter::clear('login:ip:203.0.113.5');
        RateLimiter::clear('login:ip:198.51.100.10');
        RateLimiter::clear('login:account:victim@example.com');

        for ($i = 0; $i < 10; $i++) {
            RateLimiter::clear("login:email_ip:admin@example.com|10.0.0.$i");
            RateLimiter::clear("login:ip:10.0.0.$i");
        }

        for ($i = 0; $i < 30; $i++) {
            RateLimiter::clear("login:email_ip:attacker{$i}@example.com|203.0.113.5");
            RateLimiter::clear("login:account:attacker{$i}@example.com");
        }
    }
}
