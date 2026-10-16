<?php

namespace Database\Factories;

use App\Enums\OrderConfirmationStatus;
use App\Models\Order;
use App\Models\OrderConfirmation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderConfirmation>
 */
class OrderConfirmationFactory extends Factory
{
    protected $model = OrderConfirmation::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'phone_e164' => '+2010'.fake()->numerify('########'),
            'status' => OrderConfirmationStatus::Scheduled,
            'send_at' => now()->addMinutes(3),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => OrderConfirmationStatus::Sent,
            'send_at' => now()->subMinutes(10),
            'sent_at' => now()->subMinutes(5),
        ]);
    }

    public function status(OrderConfirmationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
