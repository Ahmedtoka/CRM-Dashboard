<?php

namespace Database\Factories;

use App\Models\AdPlatformConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdPlatformConnection> */
class AdPlatformConnectionFactory extends Factory
{
    protected $model = AdPlatformConnection::class;

    public function definition(): array
    {
        return [
            'platform' => 'meta',
            'name' => fake()->company().' Ads',
            'credentials' => ['access_token' => 'tok_'.fake()->uuid()],
            'status' => 'connected',
        ];
    }

    public function meta(): static
    {
        return $this->state(['platform' => 'meta']);
    }

    public function tiktok(): static
    {
        return $this->state(['platform' => 'tiktok']);
    }

    public function google(): static
    {
        return $this->state(['platform' => 'google']);
    }
}
