<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'title' => fake()->words(3, true),
            'sku' => strtoupper(fake()->bothify('SKU-####')),
            'qty' => fake()->numberBetween(1, 3),
            'price' => fake()->randomFloat(2, 50, 500),
        ];
    }
}
