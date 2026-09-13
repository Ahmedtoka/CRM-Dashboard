<?php

namespace Database\Factories;

use App\Enums\ShipmentStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Shipment>
 */
class ShipmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'carrier' => 'fake-carrier',
            'tracking_number' => strtoupper(fake()->bothify('TRK########')),
            'status' => ShipmentStatus::Created,
        ];
    }
}
