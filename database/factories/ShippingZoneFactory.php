<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShippingZone>
 */
class ShippingZoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shopify_zone_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'name' => 'Egypt',
            'countries' => ['EG'],
        ];
    }
}
