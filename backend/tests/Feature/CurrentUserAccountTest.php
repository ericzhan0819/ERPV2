<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasswordHasBeenChanged;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CurrentUserAccountTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('selfAccountRoleProvider')]
    public function test_every_authenticated_role_can_update_its_own_profile(string $role): void
    {
        $user = User::factory()->create([
            'name' => '原始名稱',
            'username' => null,
            'role' => $role,
            'is_admin' => $role === User::ROLE_ADMIN,
            'must_change_password' => true,
        ]);
        AuditLog::query()->delete();

        $this->actingAs($user, 'web')
            ->patchJson('/api/me/profile', [
                'name' => ' 更新後名稱 ',
                'username' => ' My.Account ',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', '更新後名稱')
            ->assertJsonPath('data.username', 'my.account')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $user->refresh();
        $this->assertSame('更新後名稱', $user->name);
        $this->assertSame('my.account', $user->username);

        $audit = AuditLog::query()->where('subject_type', 'user')->where('action', 'updated')->sole();
        $this->assertSame('原始名稱', $audit->before_values['name']);
        $this->assertSame('更新後名稱', $audit->after_values['name']);
        $this->assertNull($audit->before_values['username']);
        $this->assertSame('my.account', $audit->after_values['username']);
    }

    public static function selfAccountRoleProvider(): array
    {
        return [
            'admin' => [User::ROLE_ADMIN],
            'manager' => [User::ROLE_MANAGER],
            'sales' => [User::ROLE_SALES],
            'unknown role remains limited to self account routes' => ['future_role'],
        ];
    }

    public function test_profile_endpoint_rejects_every_read_only_or_separate_workflow_field(): void
    {
        $user = User::factory()->withUsername('keep.me')->create();
        $forbidden = [
            'email' => 'attacker@example.com',
            'role' => User::ROLE_ADMIN,
            'is_admin' => true,
            'is_active' => false,
            'phone' => '0900000000',
            'job_title' => '老闆',
            'hire_date' => '2026-07-25',
            'notes' => '不可代寫',
            'must_change_password' => false,
            'password' => 'not-accepted',
        ];

        $this->actingAs($user, 'web')
            ->patchJson('/api/me/profile', [
                'name' => '攻擊者名稱',
                'username' => 'attacker',
                ...$forbidden,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($forbidden));

        $user->refresh();
        $this->assertNotSame('攻擊者名稱', $user->name);
        $this->assertSame('keep.me', $user->username);
        $this->assertNotSame('attacker@example.com', $user->email);
    }

    public function test_profile_endpoint_requires_the_complete_username_field_and_returns_unique_errors(): void
    {
        User::factory()->withUsername('taken.name')->create();
        $user = User::factory()->withUsername('keep.me')->create();

        $this->actingAs($user, 'web')
            ->patchJson('/api/me/profile', ['name' => '缺少帳號欄位'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        $this->actingAs($user, 'web')
            ->patchJson('/api/me/profile', [
                'name' => '重複帳號',
                'username' => ' TAKEN.NAME ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username')
            ->assertJsonPath('errors.username.0', '此帳號名稱已被使用');

        $this->assertSame('keep.me', $user->fresh()->username);
    }

    public function test_profile_service_converts_a_stale_unique_check_into_validation_error(): void
    {
        User::factory()->withUsername('race.winner')->create();
        $loser = User::factory()->withUsername('race.loser')->create(['name' => '原始名稱']);

        try {
            app(UserService::class)->updateCurrentProfile($loser, [
                'name' => '不應部分更新',
                'username' => 'race.winner',
            ]);
            $this->fail('Database unique constraint 必須拒絕重複 username。');
        } catch (ValidationException $exception) {
            $this->assertSame(['此帳號名稱已被使用'], $exception->errors()['username'] ?? null);
        }

        $loser->refresh();
        $this->assertSame('原始名稱', $loser->name);
        $this->assertSame('race.loser', $loser->username);
    }

    public function test_flagged_user_can_change_password_clear_flag_and_regenerate_current_session(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'email' => 'password-change@example.com',
            'password' => Hash::make('old-password'),
        ]);
        $this->withHeaders(['Referer' => 'http://localhost:5173']);

        $this->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'old-password',
        ])->assertOk();
        AuditLog::query()->delete();
        $probe = new class
        {
            public ?string $sessionId = null;
        };
        User::updating(function (User $updatedUser) use ($probe, $user): void {
            if ($updatedUser->is($user) && $updatedUser->isDirty('password')) {
                $probe->sessionId = session()->getId();
            }
        });

        $this->patchJson('/api/me/password', [
            'current_password' => 'old-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($probe->sessionId);
        $this->assertNotSame($probe->sessionId, session()->getId());

        $audit = AuditLog::query()->where('subject_type', 'user')->where('action', 'updated')->sole();
        $this->assertTrue((bool) $audit->before_values['must_change_password']);
        $this->assertFalse((bool) $audit->after_values['must_change_password']);
        $this->assertArrayNotHasKey('password', $audit->before_values);
        $this->assertArrayNotHasKey('password', $audit->after_values);
        $encoded = json_encode([$audit->before_values, $audit->after_values], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('old-password', $encoded);
        $this->assertStringNotContainsString('new-password-123', $encoded);
        $this->assertStringNotContainsString($user->password, $encoded);

        Auth::forgetGuards();
        $this->getJson('/api/dashboard/summary')->assertOk();
    }

    public function test_profile_change_does_not_regenerate_the_session_id(): void
    {
        $user = User::factory()->withUsername('profile.session')->create([
            'password' => Hash::make('old-password'),
        ]);
        $this->withHeaders(['Referer' => 'http://localhost:5173']);
        $this->postJson('/api/login', [
            'login' => 'profile.session',
            'password' => 'old-password',
        ])->assertOk();
        $probe = new class
        {
            public ?string $sessionId = null;
        };
        User::updating(function (User $updatedUser) use ($probe, $user): void {
            if ($updatedUser->is($user) && $updatedUser->isDirty(['name', 'username'])) {
                $probe->sessionId = session()->getId();
            }
        });

        $this->patchJson('/api/me/profile', [
            'name' => '更新後名稱',
            'username' => 'profile.session',
        ])->assertOk();

        $this->assertNotNull($probe->sessionId);
        $this->assertSame($probe->sessionId, session()->getId());
    }

    public function test_password_change_requires_current_password_and_confirmation(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($user, 'web')
            ->patchJson('/api/me/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password')
            ->assertJsonPath('errors.current_password.0', '目前密碼不正確');

        $this->actingAs($user, 'web')
            ->patchJson('/api/me/password', [
                'current_password' => 'old-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'different-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password')
            ->assertJsonPath('errors.password.0', '新密碼與確認密碼不一致');

        $user->refresh();
        $this->assertTrue(Hash::check('old-password', $user->password));
        $this->assertTrue($user->must_change_password);
    }

    #[DataProvider('nonStringCurrentPasswordProvider')]
    public function test_current_password_non_string_input_returns_validation_error_without_changing_account(mixed $currentPassword): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($user, 'web')
            ->patchJson('/api/me/password', [
                'current_password' => $currentPassword,
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password')
            ->assertJsonPath('errors.current_password.0', '目前密碼格式不正確');

        $user->refresh();
        $this->assertTrue(Hash::check('old-password', $user->password));
        $this->assertTrue($user->must_change_password);
    }

    public static function nonStringCurrentPasswordProvider(): array
    {
        return [
            'list' => [['old-password']],
            'nested object' => [['nested' => 'old-password']],
            'boolean' => [true],
            'integer' => [12345678],
        ];
    }

    public function test_flagged_user_cannot_clear_required_state_by_reusing_current_password(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'password' => Hash::make('default-pass-1'),
        ]);

        $this->actingAs($user, 'web')
            ->patchJson('/api/me/password', [
                'current_password' => 'default-pass-1',
                'password' => 'default-pass-1',
                'password_confirmation' => 'default-pass-1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password')
            ->assertJsonPath('errors.password.0', '新密碼不可與目前密碼相同');

        $user->refresh();
        $this->assertTrue(Hash::check('default-pass-1', $user->password));
        $this->assertTrue($user->must_change_password);
    }

    public function test_password_endpoint_rejects_fields_owned_by_other_account_workflows(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'name' => '原始名稱',
            'username' => null,
            'password' => Hash::make('old-password'),
        ]);
        $separateWorkflowFields = [
            'name' => '攻擊者名稱',
            'username' => 'attacker',
            'email' => 'attacker@example.com',
            'role' => User::ROLE_ADMIN,
            'is_admin' => true,
            'is_active' => false,
            'phone' => '0900000000',
            'job_title' => '老闆',
            'hire_date' => '2026-07-26',
            'notes' => '不可代寫',
            'must_change_password' => false,
        ];

        $this->actingAs($user, 'web')
            ->patchJson('/api/me/password', [
                'current_password' => 'old-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
                ...$separateWorkflowFields,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($separateWorkflowFields));

        $user->refresh();
        $this->assertSame('原始名稱', $user->name);
        $this->assertNull($user->username);
        $this->assertTrue(Hash::check('old-password', $user->password));
        $this->assertTrue($user->must_change_password);
    }

    public function test_password_change_without_a_session_store_does_not_commit_then_return_500(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($user, 'web')
            ->patchJson('/api/me/password', [
                'current_password' => 'old-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertFalse($user->must_change_password);
    }

    public function test_regular_self_password_change_is_traceable_by_actor_time_and_request_path(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
            'must_change_password' => false,
        ]);
        AuditLog::query()->delete();

        $this->actingAs($user, 'web')
            ->patchJson('/api/me/password', [
                'current_password' => 'old-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertOk();

        $audit = AuditLog::query()->where('subject_type', 'user')->where('action', 'updated')->sole();
        $this->assertSame($user->id, $audit->actor_id);
        $this->assertSame($user->id, $audit->subject_id);
        $this->assertSame('PATCH', $audit->request_method);
        $this->assertSame('api/me/password', $audit->request_path);
        $this->assertNotNull($audit->created_at);
        $this->assertNull($audit->before_values);
        $this->assertNull($audit->after_values);
    }

    public function test_password_change_rolls_back_when_audit_cannot_be_recorded(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'password' => Hash::make('old-password'),
        ]);
        $this->mock(AuditLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordModelEvent')
                ->once()
                ->andThrow(new RuntimeException('audit unavailable'));
        });

        try {
            app(UserService::class)->updateCurrentPassword($user, 'new-password-123');
            $this->fail('稽核寫入失敗時，自助改密碼不得留下部分完成狀態。');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $user->refresh();
        $this->assertTrue(Hash::check('old-password', $user->password));
        $this->assertTrue($user->must_change_password);
    }

    public function test_profile_change_rolls_back_when_audit_cannot_be_recorded(): void
    {
        $user = User::factory()->withUsername('old.username')->create(['name' => '原始名稱']);
        $this->mock(AuditLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordModelEvent')
                ->once()
                ->andThrow(new RuntimeException('audit unavailable'));
        });

        try {
            app(UserService::class)->updateCurrentProfile($user, [
                'name' => '不應保存',
                'username' => 'new.username',
            ]);
            $this->fail('稽核寫入失敗時，自助個人資料不得留下部分完成狀態。');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $user->refresh();
        $this->assertSame('原始名稱', $user->name);
        $this->assertSame('old.username', $user->username);
    }

    public function test_flagged_user_can_read_me_and_logout(): void
    {
        $user = User::factory()->mustChangePassword()->create();
        $this->withHeaders(['Referer' => 'http://localhost:5173']);

        $this->actingAs($user, 'web')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);

        $this->postJson('/api/logout')
            ->assertOk()
            ->assertExactJson(['message' => '已登出']);

        Auth::forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    #[DataProvider('blockedOperationalRouteProvider')]
    public function test_flagged_user_is_blocked_from_every_operational_module(string $method, string $uri): void
    {
        $user = User::factory()->admin()->mustChangePassword()->create();

        $response = $this->actingAs($user, 'web')->json($method, $uri);

        $response
            ->assertConflict()
            ->assertExactJson([
                'message' => '請先修改密碼',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
            ]);
    }

    public static function blockedOperationalRouteProvider(): array
    {
        return [
            'dashboard' => ['GET', '/api/dashboard/summary'],
            'vehicle list' => ['GET', '/api/vehicles'],
            'vehicle binding is behind the gate' => ['GET', '/api/vehicles/999999'],
            'customers' => ['GET', '/api/customers'],
            'money entries' => ['GET', '/api/money-entries'],
            'cash accounts' => ['GET', '/api/cash-accounts/options'],
            'admin users' => ['GET', '/api/users'],
            'salary' => ['GET', '/api/salary-periods'],
            'audit logs' => ['GET', '/api/audit-logs'],
        ];
    }

    public function test_inactive_status_wins_before_password_gate_and_guest_still_gets_unauthorized(): void
    {
        $inactive = User::factory()->mustChangePassword()->create(['is_active' => false]);
        $this->withHeaders(['Referer' => 'http://localhost:5173']);

        $this->actingAs($inactive, 'web')
            ->getJson('/api/dashboard/summary')
            ->assertForbidden()
            ->assertExactJson(['message' => '此帳號已被停用']);

        Auth::forgetGuards();
        $this->getJson('/api/dashboard/summary')->assertUnauthorized();
    }

    public function test_user_without_required_flag_can_reach_operational_routes(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        $this->actingAs($admin, 'web')
            ->getJson('/api/dashboard/summary')
            ->assertOk();
    }

    #[DataProvider('flaggedRestrictedRoleProvider')]
    public function test_password_gate_runs_before_restricted_role_middleware(string $role): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'role' => $role,
            'is_admin' => false,
        ]);

        $this->actingAs($user, 'web')
            ->getJson('/api/users')
            ->assertConflict()
            ->assertExactJson([
                'message' => '請先修改密碼',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
            ]);
    }

    public static function flaggedRestrictedRoleProvider(): array
    {
        return [
            'sales' => [User::ROLE_SALES],
            'unknown role' => ['future_role'],
        ];
    }

    public function test_unknown_role_without_password_flag_is_rejected_by_role_middleware(): void
    {
        $user = User::factory()->create([
            'role' => 'future_role',
            'is_admin' => false,
            'must_change_password' => false,
        ]);

        $this->actingAs($user, 'web')
            ->getJson('/api/users')
            ->assertForbidden()
            ->assertExactJson(['message' => '權限不足']);
    }

    public function test_all_authenticated_routes_except_the_explicit_self_account_allowlist_have_the_gate(): void
    {
        $allowlist = [
            'api/me',
            'api/me/profile',
            'api/me/password',
        ];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }

            if (in_array($route->uri(), $allowlist, true)) {
                $this->assertTrue(
                    in_array('password.changed', $route->excludedMiddleware(), true)
                        || in_array(EnsurePasswordHasBeenChanged::class, $route->excludedMiddleware(), true),
                    "Self account route {$route->uri()} 必須明確排除強制改密碼 gate。",
                );

                continue;
            }

            $this->assertTrue(
                (in_array('password.changed', $middleware, true)
                    || in_array(EnsurePasswordHasBeenChanged::class, $middleware, true))
                    && ! in_array('password.changed', $route->excludedMiddleware(), true)
                    && ! in_array(EnsurePasswordHasBeenChanged::class, $route->excludedMiddleware(), true),
                "Authenticated route {$route->uri()} 必須受強制改密碼 gate 保護。",
            );
        }
    }
}
