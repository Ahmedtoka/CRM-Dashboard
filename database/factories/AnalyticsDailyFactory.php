<?php

namespace Database\Factories;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AnalyticsDaily>
 */
class AnalyticsDailyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => now()->toDateString(),
            'platform' => fake()->randomElement(Platform::cases()),
            'messages_sent' => fake()->numberBetween(0, 100),
            'conversations_handled' => fake()->numberBetween(0, 30),
        ];
    }
}
