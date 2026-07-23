<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class DualIdentifierLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders(['Referer' => 'http://localhost']);
    }

    public function test_login_request_requires_login_string_and_keeps_password_contract(): void
    {
        $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['login']);

        $this->postJson('/api/login', [
            'login' => ['admin@example.com'],
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['login']);

        $this->postJson('/api/login', [
            'login' => str_repeat('a', 256),
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['login']);

        $this->postJson('/api/login', [
            'login' => 'admin@example.com',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_email_login_is_trimmed_case_insensitive_and_returns_account_state(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'email' => 'Owner@Example.com',
            'username' => 'owner',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/login', [
            'login' => '  OWNER@EXAMPLE.COM  ',
            'password' => 'correct-password',
        ])->assertSuccessful()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.username', 'owner')
            ->assertJsonPath('data.must_change_password', true);

        $this->assertSame(
            ['username' => 'owner', 'must_change_password' => true],
            array_intersect_key(
                $response->json('data'),
                array_flip(['username', 'must_change_password']),
            ),
        );
        $this->assertAuthenticatedAs($user);
    }

    public function test_username_login_is_trimmed_and_case_insensitive(): void
    {
        $user = User::factory()->withUsername('sales.one')->create([
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/login', [
            'login' => '  SALES.ONE  ',
            'password' => 'correct-password',
        ])->assertSuccessful()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_without_username_can_still_login_by_email(): void
    {
        $user = User::factory()->create([
            'email' => 'legacy@example.com',
            'username' => null,
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/login', [
            'login' => 'legacy@example.com',
            'password' => 'correct-password',
        ])->assertSuccessful()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.username', null);
    }

    public function test_unknown_identifiers_and_wrong_password_share_the_same_response(): void
    {
        User::factory()->withUsername('known.user')->create([
            'email' => 'known@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        foreach ([
            ['missing@example.com', 'correct-password'],
            ['missing.user', 'correct-password'],
            ['known@example.com', 'wrong-password'],
            ['known.user', 'wrong-password'],
        ] as [$login, $password]) {
            $this->postJson('/api/login', compact('login', 'password'))
                ->assertUnprocessable()
                ->assertExactJson(['message' => '帳號或密碼錯誤']);
        }
    }

    public function test_inactive_user_cannot_login_with_either_identifier(): void
    {
        User::factory()->withUsername('disabled.user')->create([
            'email' => 'disabled@example.com',
            'password' => Hash::make('correct-password'),
            'is_active' => false,
        ]);

        foreach (['disabled@example.com', 'DISABLED.USER'] as $login) {
            $this->postJson('/api/login', [
                'login' => $login,
                'password' => 'correct-password',
            ])->assertUnprocessable()
                ->assertExactJson(['message' => '此帳號已被停用']);

            $this->assertGuest();
        }
    }

    public function test_audit_failure_invalidates_the_authenticated_session(): void
    {
        User::factory()->withUsername('audit.failure')->create([
            'password' => Hash::make('correct-password'),
        ]);

        $this->partialMock(AuditLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordAuthentication')
                ->once()
                ->with('login', Mockery::type(User::class))
                ->andThrow(new RuntimeException('audit unavailable'));
        });

        $this->postJson('/api/login', [
            'login' => 'audit.failure',
            'password' => 'correct-password',
        ])->assertInternalServerError();

        $this->getJson('/api/me')->assertUnauthorized();
    }
}
