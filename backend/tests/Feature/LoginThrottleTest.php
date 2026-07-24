<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->loginAs('admin@example.com', 'wrong-password')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
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

        // 此段說明相鄰程式碼的用途與預期行為。
        for ($i = 0; $i < 10; $i++) {
            $this->loginAs('admin@example.com', 'wrong-password', "10.0.0.$i")->assertStatus(422);
        }

        $this->loginAs('admin@example.com', 'wrong-password', '10.0.0.99')->assertStatus(429);
    }

    public function test_email_and_username_share_one_canonical_account_limiter(): void
    {
        User::factory()->withUsername('victim')->create([
            'email' => 'victim@example.com',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->loginAs('victim@example.com', 'wrong-password', "10.1.0.$i")->assertStatus(422);
        }

        for ($i = 5; $i < 10; $i++) {
            $this->loginAs('VICTIM', 'wrong-password', "10.1.0.$i")->assertStatus(422);
        }

        $this->loginAs('victim@example.com', 'wrong-password', '10.1.0.10')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_username_and_email_reverse_order_share_one_canonical_account_limiter(): void
    {
        User::factory()->withUsername('victim')->create([
            'email' => 'victim@example.com',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->loginAs('VICTIM', 'wrong-password', "10.3.0.$i")->assertStatus(422);
        }

        for ($i = 5; $i < 10; $i++) {
            $this->loginAs('VICTIM@EXAMPLE.COM', 'wrong-password', "10.3.0.$i")->assertStatus(422);
        }

        $this->loginAs('victim', 'wrong-password', '10.3.0.10')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_email_and_username_share_one_identifier_ip_limiter(): void
    {
        User::factory()->withUsername('victim')->create([
            'email' => 'victim@example.com',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->loginAs('victim@example.com', 'wrong-password')->assertStatus(422);
        }

        for ($i = 0; $i < 2; $i++) {
            $this->loginAs('VICTIM', 'wrong-password')->assertStatus(422);
        }

        $this->loginAs('victim@example.com', 'wrong-password')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_identifier_case_variants_do_not_get_new_limiter_allowance(): void
    {
        User::factory()->withUsername('victim')->create([
            'email' => 'Victim@Example.com',
        ]);

        foreach (['victim@example.com', 'VICTIM@EXAMPLE.COM', 'Victim@Example.com', 'victim', 'VICTIM'] as $login) {
            $this->loginAs($login, 'wrong-password')->assertStatus(422);
        }

        $this->loginAs('victim@example.com', 'wrong-password')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_successful_alias_login_clears_the_canonical_account_limiter(): void
    {
        User::factory()->withUsername('victim')->create([
            'email' => 'victim@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 9; $i++) {
            $login = $i % 2 === 0 ? 'victim@example.com' : 'VICTIM';
            $this->loginAs($login, 'wrong-password', "10.2.0.$i")->assertStatus(422);
        }

        $this->loginAs('VICTIM', 'correct-password', '10.2.0.9')->assertSuccessful();
        $this->postJson('/api/logout')->assertSuccessful();

        for ($i = 10; $i < 19; $i++) {
            $this->loginAs('victim@example.com', 'wrong-password', "10.2.0.$i")->assertStatus(422);
        }
    }

    public function test_successful_email_login_clears_the_canonical_alias_limiter(): void
    {
        User::factory()->withUsername('victim')->create([
            'email' => 'victim@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 9; $i++) {
            $login = $i % 2 === 0 ? 'victim' : 'VICTIM@EXAMPLE.COM';
            $this->loginAs($login, 'wrong-password', "10.5.0.$i")->assertStatus(422);
        }

        $this->loginAs('VICTIM@EXAMPLE.COM', 'correct-password', '10.5.0.9')->assertSuccessful();
        $this->postJson('/api/logout')->assertSuccessful();

        for ($i = 10; $i < 19; $i++) {
            $this->loginAs('VICTIM', 'wrong-password', "10.5.0.$i")->assertStatus(422);
        }
    }

    public function test_same_ip_rotating_unknown_username_and_email_failures_are_blocked_by_ip_wide_limiter(): void
    {
        User::factory()->create(['email' => 'victim@example.com']);

        for ($i = 0; $i < 30; $i++) {
            $login = $i % 2 === 0
                ? "unknown.user{$i}"
                : "attacker{$i}@example.com";

            $this->loginAs($login, 'wrong-password', '203.0.113.5')->assertStatus(422);
        }

        $this->loginAs('victim@example.com', 'wrong-password', '203.0.113.5')->assertStatus(429);
    }

    public function test_more_than_thirty_successful_logins_from_one_ip_are_not_blocked(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        // 此段說明相鄰程式碼的用途與預期行為。
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

        // 此段說明相鄰程式碼的用途與預期行為。
        $this->loginAs('admin@example.com', 'correct-password')->assertStatus(429);

        $this->assertGuest();
    }

    public function test_ip_blocked_request_does_not_query_the_database(): void
    {
        $ip = '203.0.113.99';
        $ipKey = 'login:ip:'.$ip;

        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit($ipKey, 60);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->loginAs('blocked@example.com', 'wrong-password', $ip)
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_successful_login_clears_identifier_ip_and_account_limiters(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->loginAs('admin@example.com', 'wrong-password')->assertStatus(422);
        }

        $this->loginAs('admin@example.com', 'correct-password')->assertSuccessful();

        // 此段說明相鄰程式碼的用途與預期行為。
        $this->loginAs('admin@example.com', 'wrong-password')->assertStatus(422);
    }

    public function test_successful_login_does_not_clear_another_users_account_limiter(): void
    {
        User::factory()->withUsername('first.user')->create([
            'email' => 'first@example.com',
            'password' => bcrypt('correct-password'),
        ]);
        User::factory()->withUsername('second.user')->create([
            'email' => 'second@example.com',
        ]);

        for ($i = 0; $i < 9; $i++) {
            $this->loginAs('second.user', 'wrong-password', "10.4.0.$i")->assertStatus(422);
        }

        $this->loginAs('first@example.com', 'correct-password', '10.4.0.9')->assertSuccessful();
        $this->postJson('/api/logout')->assertSuccessful();

        $this->loginAs('second@example.com', 'wrong-password', '10.4.0.10')->assertStatus(422);
        $this->loginAs('SECOND.USER', 'wrong-password', '10.4.0.11')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    private function loginAs(string $login, string $password, string $ip = '127.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['Referer' => 'http://localhost'])
            ->postJson('/api/login', [
                'login' => $login,
                'password' => $password,
            ]);
    }

    private function clearAllLimiters(): void
    {
        RateLimiter::clear('login:account:uid:1');
        RateLimiter::clear('login:identifier_ip:uid:1|127.0.0.1');
        RateLimiter::clear('login:ip:127.0.0.1');
        RateLimiter::clear('login:ip:203.0.113.5');
        RateLimiter::clear('login:ip:203.0.113.99');
        RateLimiter::clear('login:ip:198.51.100.10');

        for ($i = 0; $i < 10; $i++) {
            RateLimiter::clear("login:identifier_ip:uid:1|10.0.0.$i");
            RateLimiter::clear("login:ip:10.0.0.$i");
            RateLimiter::clear("login:identifier_ip:uid:1|10.1.0.$i");
            RateLimiter::clear("login:ip:10.1.0.$i");
            RateLimiter::clear("login:identifier_ip:uid:1|10.2.0.$i");
            RateLimiter::clear("login:ip:10.2.0.$i");
            RateLimiter::clear("login:identifier_ip:uid:1|10.3.0.$i");
            RateLimiter::clear("login:ip:10.3.0.$i");
            RateLimiter::clear("login:identifier_ip:uid:2|10.4.0.$i");
            RateLimiter::clear("login:ip:10.4.0.$i");
            RateLimiter::clear("login:identifier_ip:uid:1|10.5.0.$i");
            RateLimiter::clear("login:ip:10.5.0.$i");
        }

        RateLimiter::clear('login:identifier_ip:uid:1|10.1.0.10');
        RateLimiter::clear('login:ip:10.1.0.10');
        RateLimiter::clear('login:identifier_ip:uid:1|10.3.0.10');
        RateLimiter::clear('login:ip:10.3.0.10');
        RateLimiter::clear('login:identifier_ip:uid:2|10.4.0.10');
        RateLimiter::clear('login:ip:10.4.0.10');
        RateLimiter::clear('login:identifier_ip:uid:2|10.4.0.11');
        RateLimiter::clear('login:ip:10.4.0.11');
        RateLimiter::clear('login:account:uid:2');

        for ($i = 10; $i < 19; $i++) {
            RateLimiter::clear("login:identifier_ip:uid:1|10.2.0.$i");
            RateLimiter::clear("login:ip:10.2.0.$i");
            RateLimiter::clear("login:identifier_ip:uid:1|10.5.0.$i");
            RateLimiter::clear("login:ip:10.5.0.$i");
        }

        for ($i = 0; $i < 30; $i++) {
            $login = $i % 2 === 0
                ? "unknown.user{$i}"
                : "attacker{$i}@example.com";
            $rawIdentity = hash('sha256', $login);

            RateLimiter::clear("login:identifier_ip:raw:{$rawIdentity}|203.0.113.5");
            RateLimiter::clear("login:account:raw:{$rawIdentity}");
        }
    }
}
