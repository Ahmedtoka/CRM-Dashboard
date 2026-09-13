<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerIdentity>
 */
class CustomerIdentityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'platform' => fake()->randomElement(Platform::cases()),
            'external_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'username' => fake()->userName(),
            'display_name' => fake()->name(),
        ];
    }
}
