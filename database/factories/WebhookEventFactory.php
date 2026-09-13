<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => 'meta',
            'event_type' => 'message',
            'dedupe_key' => (string) fake()->unique()->uuid(),
            'payload' => ['raw' => fake()->sentence()],
            'signature_valid' => true,
            'status' => 'received',
        ];
    }
}
