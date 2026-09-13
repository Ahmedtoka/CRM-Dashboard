<?php

namespace Database\Factories;

use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShippingRate>
 */
class ShippingRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shipping_zone_id' => ShippingZone::factory(),
            'shopify_rate_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'title' => 'Standard Shipping',
            'price' => fake()->randomFloat(2, 40, 90),
            'min_order_subtotal' => null,
            'max_order_subtotal' => null,
        ];
    }
}
