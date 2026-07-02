<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        // A client retry after a lost/timed-out response must not be
        // rejected with 401 just because the first call already logged the
        // session out and invalidated it.
        $this->actingAs($user, 'web')
            ->logoutRequest()
            ->assertSuccessful();

        $this->logoutRequest()->assertSuccessful();
    }

    public function test_logout_succeeds_even_when_never_authenticated(): void
    {
        $this->logoutRequest()->assertSuccessful();
    }

    private function logoutRequest(): \Illuminate\Testing\TestResponse
    {
        // Referer must match a configured Sanctum stateful domain (see
        // SANCTUM_STATEFUL_DOMAINS in .env) so EnsureFrontendRequestsAreStateful
        // starts the session for this request, matching how a real SPA call
        // from the frontend dev server behaves.
        return $this->withHeaders(['Referer' => 'http://localhost:5173'])->postJson('/api/logout');
    }
}
