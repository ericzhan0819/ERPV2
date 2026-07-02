<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
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
            $this->postJson('/api/login', [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_login_still_returns_normal_failure_response_below_limit(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }
    }

    public function test_same_email_rotating_ip_is_blocked_by_account_limiter(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        // 10 failures spread across 10 different IPs stays under the 5/IP limiter
        // but must trip the 10/15min account-only limiter on the 11th attempt.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ], ['REMOTE_ADDR' => "10.0.0.$i"])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ], ['REMOTE_ADDR' => '10.0.0.99'])->assertStatus(429);
    }

    public function test_same_ip_rotating_email_is_blocked_by_ip_wide_limiter(): void
    {
        User::factory()->create(['email' => 'victim@example.com']);

        // 30 failures against 30 different (mostly non-existent) accounts from the
        // same IP must trip the 30/60s IP-wide limiter on the 31st attempt.
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/login', [
                'email' => "attacker{$i}@example.com",
                'password' => 'wrong-password',
            ], ['REMOTE_ADDR' => '203.0.113.5'])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'victim@example.com',
            'password' => 'wrong-password',
        ], ['REMOTE_ADDR' => '203.0.113.5'])->assertStatus(429);
    }

    public function test_blocked_request_does_not_attempt_authentication_again(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // Even with the correct password, a blocked email+IP pair must be
        // rejected with 429 instead of reaching Auth::attempt and logging in.
        $response = $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(429);
        $this->assertGuest();
    }

    public function test_successful_login_clears_email_ip_and_account_limiters(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ])->assertSuccessful();

        // Previous failures against this email+IP must not carry over after a
        // successful login clears the email_ip and account limiters.
        $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    private function clearAllLimiters(): void
    {
        RateLimiter::clear('login:email_ip:admin@example.com|127.0.0.1');
        RateLimiter::clear('login:account:admin@example.com');
        RateLimiter::clear('login:ip:127.0.0.1');
        RateLimiter::clear('login:ip:203.0.113.5');
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
