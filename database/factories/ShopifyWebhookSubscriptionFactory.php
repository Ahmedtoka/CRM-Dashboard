<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShopifyWebhookSubscription>
 */
class ShopifyWebhookSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'topic' => 'orders/'.fake()->unique()->word(),
            'shopify_subscription_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'callback_url' => 'https://example.com/webhooks/shopify',
            'last_received_at' => null,
            'registered_at' => now(),
        ];
    }
}
