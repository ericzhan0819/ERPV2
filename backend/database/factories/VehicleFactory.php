<?php

namespace Database\Factories;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'stock_no' => 'V'.now()->format('Ymd').fake()->unique()->numerify('####'),
            'status' => 'preparing',
            'brand' => fake()->company(),
            'model' => fake()->word(),
            'year' => fake()->numberBetween(2000, 2025),
            'license_plate' => strtoupper(fake()->bothify('???-####')),
            'vin' => null,
            'mileage_km' => fake()->numberBetween(1000, 200000),
            'color' => fake()->safeColorName(),
        ];
    }
}
