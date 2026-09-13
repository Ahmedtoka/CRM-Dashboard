<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShopifySyncRun>
 */
class ShopifySyncRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => 'manual',
            'resource' => 'orders',
            'range_from' => null,
            'range_to' => null,
            'status' => 'completed',
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped_stale' => 0,
            'failed' => 0,
            'errors' => [],
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
