<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\MoneyEntry;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\MoneyEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessBoundaryRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_role_cannot_read_vehicle_data_or_money_entries(): void
    {
        $user = User::factory()->create(['role' => 'future_role']);
        $vehicle = Vehicle::factory()->create(['seller_phone' => '0912345678', 'notes' => 'private vehicle notes']);
        MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id,
            'category' => '購車付款',
            'counterparty_name' => 'PRIVATE SELLER',
            'description' => 'purchase cost 500000',
        ]);

        foreach (['/api/vehicles', "/api/vehicles/{$vehicle->id}", "/api/vehicles/{$vehicle->id}/money-entries", "/api/vehicles/{$vehicle->id}/photos"] as $url) {
            $this->actingAs($user, 'web')->getJson($url)
                ->assertForbidden()
                ->assertJsonMissingPath('data.0.counterparty_name')
                ->assertJsonMissingPath('data.0.description')
                ->assertDontSee('PRIVATE SELLER')
                ->assertDontSee('purchase cost 500000')
                ->assertDontSee('0912345678')
                ->assertDontSee('private vehicle notes');
        }

        foreach ([$user, null] as $actor) {
            $entries = app(MoneyEntryService::class)->listEntries(['vehicle_id' => $vehicle->id], $actor);
            $this->assertSame(0, $entries->total());
        }
    }

    #[DataProvider('cashAccountFilterRoles')]
    public function test_cash_account_filter_is_restricted_to_financial_roles(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $vehicle = Vehicle::factory()->create();
        $account = CashAccount::factory()->create();
        $otherAccount = CashAccount::factory()->create();
        $entry = MoneyEntry::factory()->create([
            'vehicle_id' => $vehicle->id, 'cash_account_id' => $account->id, 'category' => '訂金收入',
        ]);

        foreach (['/api/money-entries', "/api/vehicles/{$vehicle->id}/money-entries"] as $url) {
            $this->actingAs($user, 'web')->getJson($url)->assertOk()->assertJsonCount(1, 'data');
            foreach ([$account->id, $otherAccount->id, 999999, ''] as $accountId) {
                $response = $this->getJson($url.'?cash_account_id='.$accountId);
                if ($role === User::ROLE_SALES) {
                    $response->assertUnprocessable()->assertJsonValidationErrors('cash_account_id')->assertJsonMissingPath('data');
                } elseif ($accountId === 999999) {
                    $response->assertUnprocessable();
                } else {
                    $response->assertOk()->assertJsonCount($accountId === $otherAccount->id ? 0 : 1, 'data');
                    if ($accountId === $account->id) {
                        $response->assertJsonPath('data.0.id', $entry->id);
                    }
                }
            }
        }
    }

    public static function cashAccountFilterRoles(): array
    {
        return [['admin'], ['manager'], ['sales']];
    }

    public function test_password_guesses_are_limited_across_ips_and_expire(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password'), 'must_change_password' => true]);
        $this->actingAs($user, 'web');
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])
                ->patchJson('/api/me/password', $this->passwordPayload('wrong-password'))
                ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        }
        $this->patchJson('/api/me/password', $this->passwordPayload('old-password'))
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
        $this->assertTrue($user->fresh()->must_change_password);

        $this->travel(901)->seconds();
        $this->patchJson('/api/me/password', $this->passwordPayload('old-password'))->assertOk();
        $this->assertSame(0, RateLimiter::attempts('login:account:uid:'.$user->id));
    }

    public function test_successful_password_change_clears_only_its_account_failures(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);
        $other = User::factory()->create();
        $key = 'login:account:uid:'.$user->id;
        $otherKey = 'login:account:uid:'.$other->id;
        RateLimiter::hit($otherKey, 900);
        $this->actingAs($user, 'web')->patchJson('/api/me/password', $this->passwordPayload('wrong-password'))->assertUnprocessable();
        $this->patchJson('/api/me/password', $this->passwordPayload('old-password', 'mismatch'))->assertUnprocessable();
        $this->assertSame(1, RateLimiter::attempts($key));
        $this->patchJson('/api/me/password', $this->passwordPayload(['bad-input']))->assertUnprocessable();
        $this->assertSame(1, RateLimiter::attempts($key));
        $this->patchJson('/api/me/password', $this->passwordPayload('old-password'))->assertOk();
        $this->assertSame(0, RateLimiter::attempts($key));
        $this->assertSame(1, RateLimiter::attempts($otherKey));
    }

    public function test_login_and_password_change_share_account_attempts(): void
    {
        $user = User::factory()->create(['username' => 'test.user', 'password' => Hash::make('old-password')]);
        for ($i = 1; $i <= 9; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.$i"])
                ->withHeader('Origin', 'http://localhost:5173')
                ->postJson('/api/login', ['login' => $i % 2 ? 'TEST.USER' : $user->email, 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }
        $this->actingAs($user, 'web')->patchJson('/api/me/password', $this->passwordPayload('wrong-password'))->assertUnprocessable();
        $this->patchJson('/api/me/password', $this->passwordPayload('old-password'))->assertStatus(429);
        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'old-password'])->assertStatus(429);
    }

    private function passwordPayload(mixed $currentPassword, string $confirmation = 'new-password-123'): array
    {
        return ['current_password' => $currentPassword, 'password' => 'new-password-123', 'password_confirmation' => $confirmation];
    }
}
