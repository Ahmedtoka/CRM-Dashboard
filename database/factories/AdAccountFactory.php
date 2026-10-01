<?php

namespace Database\Factories;

use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdAccount> */
class AdAccountFactory extends Factory
{
    protected $model = AdAccount::class;

    public function definition(): array
    {
        return [
            'connection_id' => fn (array $a) => AdPlatformConnection::factory()->state(['platform' => $a['platform']]),
            'platform' => 'meta',
            'external_id' => 'act_'.fake()->unique()->numerify('##########'),
            'name' => fake()->company(),
            'currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'status' => 'ACTIVE',
            'balance' => 0,
            'is_active' => true,
        ];
    }

    public function meta(): static
    {
        return $this->state(['platform' => 'meta', 'external_id' => 'act_'.fake()->unique()->numerify('##########')]);
    }

    public function tiktok(): static
    {
        return $this->state(['platform' => 'tiktok', 'external_id' => fake()->unique()->numerify('7##############')]);
    }

    public function google(): static
    {
        return $this->state(['platform' => 'google', 'external_id' => fake()->unique()->numerify('##########')]);
    }
}
