<?php

namespace Database\Factories;

use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdAccount> */
class AdAccountFactory extends Factory
{
    protected $model = AdAccount::class;

    private static int $seq = 0;

    /** Collision-free within a process: counter plus random tail. */
    private static function uid(int $digits): string
    {
        return str_pad((string) (++self::$seq), 4, '0', STR_PAD_LEFT).fake()->numerify(str_repeat('#', max($digits - 4, 0)));
    }

    public function definition(): array
    {
        return [
            'connection_id' => fn (array $a) => AdPlatformConnection::factory()->state(['platform' => $a['platform']]),
            'platform' => 'meta',
            'external_id' => 'act_'.self::uid(10),
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
        return $this->state(fn () => ['platform' => 'meta', 'external_id' => 'act_'.self::uid(10)]);
    }

    public function tiktok(): static
    {
        return $this->state(fn () => ['platform' => 'tiktok', 'external_id' => '7'.self::uid(14)]);
    }

    public function google(): static
    {
        return $this->state(fn () => ['platform' => 'google', 'external_id' => self::uid(10)]);
    }
}
