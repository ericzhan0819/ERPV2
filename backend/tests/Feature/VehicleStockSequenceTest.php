<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\UsesCommissionAttributionFixtures;
use Tests\TestCase;

class VehicleStockSequenceTest extends TestCase
{
    use RefreshDatabase;
    use UsesCommissionAttributionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommissionAttributionFixtures();
    }

    public function test_different_vehicle_requests_receive_distinct_daily_stock_numbers(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        try {
            $user = User::factory()->create();
            $service = app(VehicleService::class);

            $first = $service->createVehicle($this->payload('AAA-0001'), $user->id);
            $second = $service->createVehicle($this->payload('AAA-0002'), $user->id);

            $this->assertSame('V202607070001', $first->stock_no);
            $this->assertSame('V202607070002', $second->stock_no);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_sequence_reconciles_vehicles_created_before_the_sequence_table_was_used(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        try {
            $user = User::factory()->create();
            Vehicle::factory()->create(['stock_no' => 'V202607070042']);

            $vehicle = app(VehicleService::class)->createVehicle($this->payload('AAA-0043'), $user->id);

            $this->assertSame('V202607070043', $vehicle->stock_no);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_failed_create_rolls_back_the_daily_sequence_increment(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        try {
            $user = User::factory()->create();
            $inactiveAccount = CashAccount::factory()->create(['is_active' => false]);
            $service = app(VehicleService::class);

            try {
                $service->createVehicle(array_merge($this->payload('AAA-FAIL'), [
                    'initial_purchase_payment' => [
                        'amount' => 100000,
                        'cash_account_id' => $inactiveAccount->id,
                    ],
                ]), $user->id);
                $this->fail('停用帳戶應讓建車交易失敗');
            } catch (ValidationException) {
                // Expected.
            }

            $vehicle = $service->createVehicle($this->payload('AAA-0001'), $user->id);

            $this->assertSame('V202607070001', $vehicle->stock_no);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $licensePlate): array
    {
        return [
            'brand' => 'Toyota',
            'model' => 'Camry',
            'license_plate' => $licensePlate,
            'purchase_agent_id' => $this->defaultCommissionAgent->id,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
