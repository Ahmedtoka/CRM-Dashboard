<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 2000);
        $shipping = fake()->randomFloat(2, 30, 100);

        return [
            'customer_id' => Customer::factory(),
            'type' => OrderType::Cod,
            'status' => OrderStatus::AwaitingPayment,
            'subtotal' => $subtotal,
            'shipping_fee' => $shipping,
            'discount' => 0,
            'total' => $subtotal + $shipping,
            'currency' => 'EGP',
            'shipping_name' => fake()->name(),
            'shipping_phone' => fake()->e164PhoneNumber(),
            'shipping_city' => fake()->city(),
            'shipping_address' => fake()->address(),
        ];
    }
}
