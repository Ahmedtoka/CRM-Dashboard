<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Refund>
 */
class RefundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'shopify_refund_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'amount' => fake()->randomFloat(2, 20, 500),
            'note' => null,
            'restock' => true,
            'shopify_created_at' => now(),
        ];
    }
}
