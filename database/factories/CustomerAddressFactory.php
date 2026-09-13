<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    public function definition(): array
    {
        $province = fake()->randomElement([
            ['code' => 'C', 'name' => 'Cairo'],
            ['code' => 'GZ', 'name' => 'Giza'],
        ]);

        return [
            'customer_id' => Customer::factory(),
            'shopify_address_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'name' => fake()->name(),
            'phone' => '01'.fake()->numerify('#########'),
            'address1' => fake()->streetAddress(),
            'address2' => null,
            'city' => $province['name'],
            'province' => $province['name'],
            'province_code' => $province['code'],
            'zip' => fake()->postcode(),
            'country_code' => 'EG',
            'is_default' => false,
        ];
    }
}
