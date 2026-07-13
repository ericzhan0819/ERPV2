<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class VehicleCommissionAgentResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_vehicle_resource_returns_agent_ids_and_names_for_operational_roles(): void
    {
        $purchaseAgent = User::factory()->manager()->create(['name' => '收車人']);
        $salesAgent = User::factory()->sales()->create(['name' => '賣車人']);
        $vehicle = Vehicle::factory()->create([
            'purchase_agent_id' => $purchaseAgent->id,
            'sales_agent_id' => $salesAgent->id,
        ]);

        foreach ([
            User::factory()->admin()->create(),
            User::factory()->manager()->create(),
            User::factory()->sales()->create(),
        ] as $viewer) {
            Auth::forgetGuards();
            $this->actingAs($viewer, 'web')->getJson("/api/vehicles/{$vehicle->id}")
                ->assertOk()
                ->assertJsonPath('vehicle.purchase_agent_id', $purchaseAgent->id)
                ->assertJsonPath('vehicle.purchase_agent.name', '收車人')
                ->assertJsonPath('vehicle.sales_agent_id', $salesAgent->id)
                ->assertJsonPath('vehicle.sales_agent.name', '賣車人');
        }
    }

    public function test_public_vehicle_resource_does_not_expose_internal_agent_attribution(): void
    {
        $agent = User::factory()->create();
        $vehicle = Vehicle::factory()->create([
            'status' => 'listed',
            'purchase_agent_id' => $agent->id,
            'sales_agent_id' => $agent->id,
        ]);

        $response = $this->getJson("/api/public/vehicles/{$vehicle->id}")->assertOk();
        $response->assertJsonMissingPath('data.purchase_agent_id');
        $response->assertJsonMissingPath('data.purchase_agent');
        $response->assertJsonMissingPath('data.sales_agent_id');
        $response->assertJsonMissingPath('data.sales_agent');
    }

    public function test_unknown_role_fails_closed_for_agent_attribution(): void
    {
        $agent = User::factory()->create();
        $unknown = User::factory()->create(['role' => 'future_role']);
        $vehicle = Vehicle::factory()->create([
            'purchase_agent_id' => $agent->id,
            'sales_agent_id' => $agent->id,
        ]);

        $response = $this->actingAs($unknown, 'web')->getJson("/api/vehicles/{$vehicle->id}")->assertOk();
        $response->assertJsonMissingPath('vehicle.purchase_agent_id');
        $response->assertJsonMissingPath('vehicle.purchase_agent');
        $response->assertJsonMissingPath('vehicle.sales_agent_id');
        $response->assertJsonMissingPath('vehicle.sales_agent');
    }
}
