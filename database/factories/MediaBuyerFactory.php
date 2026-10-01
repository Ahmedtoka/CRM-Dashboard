<?php

namespace Database\Factories;

use App\Models\MediaBuyer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaBuyer> */
class MediaBuyerFactory extends Factory
{
    protected $model = MediaBuyer::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => fake()->name(),
            'color' => fake()->hexColor(),
            'is_active' => true,
        ];
    }
}
