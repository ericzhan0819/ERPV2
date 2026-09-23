<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehiclePublicDescriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_manager_can_update_public_description_through_existing_vehicle_contract(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_MANAGER] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'is_admin' => $role === User::ROLE_ADMIN,
            ]);
            $vehicle = Vehicle::factory()->create(['public_description' => null]);

            $this->actingAs($user, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'license_plate' => $vehicle->license_plate,
                'public_description' => "{$role} 官網介紹",
            ])->assertOk()
                ->assertJsonPath('data.public_description', "{$role} 官網介紹");

            $this->assertDatabaseHas('vehicles', [
                'id' => $vehicle->id,
                'public_description' => "{$role} 官網介紹",
            ]);
        }
    }

    public function test_public_description_can_be_cleared_to_null(): void
    {
        $manager = User::factory()->manager()->create();
        $vehicle = Vehicle::factory()->create(['public_description' => '原有介紹']);

        $this->actingAs($manager, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'public_description' => null,
        ])->assertOk()
            ->assertJsonPath('data.public_description', null);

        $this->assertNull($vehicle->fresh()->public_description);
    }

    public function test_sales_cannot_update_public_description(): void
    {
        $sales = User::factory()->sales()->create();
        $vehicle = Vehicle::factory()->create(['public_description' => '原有介紹']);

        $this->actingAs($sales, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'public_description' => '不應儲存',
        ])->assertForbidden();

        $this->assertSame('原有介紹', $vehicle->fresh()->public_description);
    }

    public function test_public_description_rejects_more_than_2000_characters_before_database_write(): void
    {
        $manager = User::factory()->manager()->create();
        $vehicle = Vehicle::factory()->create(['public_description' => '原有介紹']);

        $this->actingAs($manager, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'public_description' => str_repeat('字', Vehicle::PUBLIC_DESCRIPTION_MAX_LENGTH + 1),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('public_description');

        $this->assertSame('原有介紹', $vehicle->fresh()->public_description);
    }

    public function test_public_description_accepts_exactly_2000_characters(): void
    {
        $manager = User::factory()->manager()->create();
        $vehicle = Vehicle::factory()->create(['public_description' => null]);
        $description = str_repeat('字', Vehicle::PUBLIC_DESCRIPTION_MAX_LENGTH);

        $this->actingAs($manager, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'public_description' => $description,
        ])->assertOk()
            ->assertJsonPath('data.public_description', $description);

        $this->assertSame($description, $vehicle->fresh()->public_description);
    }

    public function test_public_description_is_trimmed_before_the_2000_character_validation(): void
    {
        $manager = User::factory()->manager()->create();
        $vehicle = Vehicle::factory()->create(['public_description' => null]);
        $description = str_repeat('字', Vehicle::PUBLIC_DESCRIPTION_MAX_LENGTH);
        $input = "\u{FEFF}\u{200B}\n{$description}\n\u{200B}\u{FEFF}";

        $this->actingAs($manager, 'web')->patchJson("/api/vehicles/{$vehicle->id}", [
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'license_plate' => $vehicle->license_plate,
            'public_description' => $input,
        ])->assertOk()
            ->assertJsonPath('data.public_description', $description);

        $this->assertSame($description, $vehicle->fresh()->public_description);
    }
}
