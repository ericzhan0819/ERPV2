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

        RateLimiter::clear($this->throttleKey());
    }

    protected function tearDown(): void
    {
        RateLimiter::clear($this->throttleKey());

        parent::tearDown();
    }

    public function test_login_is_blocked_with_429_after_exceeding_attempt_threshold(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ]);

            $response->assertStatus(422);
        }

        $response = $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }

    public function test_login_still_returns_normal_failure_response_below_threshold(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        for ($i = 0; $i < 4; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ]);

            $response->assertStatus(422);
        }
    }

    public function test_successful_login_clears_the_rate_limiter(): void
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

        $response = $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    private function throttleKey(): string
    {
        return 'admin@example.com|127.0.0.1';
    }
}
