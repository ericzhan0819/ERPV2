<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_succeeds_for_an_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->logoutRequest()
            ->assertSuccessful();

        $this->assertGuest('web');
    }

    public function test_logout_is_idempotent_when_retried_after_session_already_invalidated(): void
    {
        $user = User::factory()->create();

        // 此段說明相鄰程式碼的用途與預期行為。
        $this->actingAs($user, 'web')
            ->logoutRequest()
            ->assertSuccessful();

        $this->logoutRequest()->assertSuccessful();
    }

    public function test_logout_succeeds_even_when_never_authenticated(): void
    {
        $this->logoutRequest()->assertSuccessful();
    }

    public function test_non_stateful_logout_is_an_explicit_idempotent_noop(): void
    {
        $this->postJson('/api/logout')
            ->assertOk()
            ->assertExactJson(['message' => '無可登出的工作階段']);
    }

    private function logoutRequest(): TestResponse
    {
        // 此段說明相鄰程式碼的用途與預期行為。
        return $this->withHeaders(['Referer' => 'http://localhost:5173'])->postJson('/api/logout');
    }
}
