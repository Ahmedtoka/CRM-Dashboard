<?php

namespace Database\Factories;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChannelAccount>
 */
class ChannelAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'platform' => fake()->randomElement(Platform::cases()),
            'name' => fake()->company(),
            'external_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'driver' => 'fake',
            'status' => 'connected',
        ];
    }
}
