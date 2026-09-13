<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Fulfillment>
 */
class FulfillmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'shopify_fulfillment_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'status' => 'success',
            'tracking_company' => 'Bosta',
            'tracking_number' => strtoupper(fake()->bothify('TRK-########')),
            'tracking_url' => 'https://example.com/track/'.fake()->uuid(),
            'shipment_status' => 'in_transit',
            'shopify_created_at' => now(),
            'shopify_updated_at' => now(),
        ];
    }
}
