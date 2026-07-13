<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimezoneBusinessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_application_and_mysql_connections_use_taipei_business_timezone(): void
    {
        $this->assertSame('Asia/Taipei', config('app.timezone'));
        $this->assertSame('+08:00', config('database.connections.mysql.timezone'));
        $this->assertSame('+08:00', config('database.connections.mariadb.timezone'));
        $this->assertSame('Asia/Taipei', now()->timezoneName);
    }

    public function test_dashboard_and_api_use_taipei_month_boundary_and_offset(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 1, 0, 30, 0, 'Asia/Taipei'));
        $admin = User::factory()->admin()->create();
        $julyVehicle = Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-07-01 00:10:00',
        ]);
        Vehicle::factory()->create([
            'status' => 'sold',
            'sold_at' => '2026-06-30 23:59:59',
        ]);

        $this->actingAs($admin, 'web')
            ->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('monthly_sold_count', 1);

        $this->actingAs($admin, 'web')
            ->getJson("/api/vehicles/{$julyVehicle->id}")
            ->assertOk()
            ->assertJsonPath('vehicle.sold_at', '2026-07-01T00:10:00+08:00');
    }
}
