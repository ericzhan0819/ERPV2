<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** 此段說明相鄰程式碼的用途與預期行為。 */
    protected static ?string $password;

    /** 此段說明相鄰程式碼的用途與預期行為。 */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'username' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'must_change_password' => false,
            'remember_token' => Str::random(10),
            'role' => User::ROLE_MANAGER,
            'is_admin' => false,
            'is_active' => true,
        ];
    }

    /** 此段說明相鄰程式碼的用途與預期行為。 */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ADMIN,
            'is_admin' => true,
        ]);
    }

    public function manager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_MANAGER,
            'is_admin' => false,
        ]);
    }

    public function sales(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_SALES,
            'is_admin' => false,
        ]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'must_change_password' => true,
        ]);
    }

    public function withUsername(?string $username = null): static
    {
        return $this->state(fn (array $attributes) => [
            'username' => User::normalizeUsername($username ?? 'user_'.Str::random(12)),
        ]);
    }
}
