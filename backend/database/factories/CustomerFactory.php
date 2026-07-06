<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->numerify('09########'),
            'line_id' => null,
            'customer_type' => Customer::TYPE_OTHER,
            'source' => '個人',
            'address' => null,
            'notes' => null,
        ];
    }
}
