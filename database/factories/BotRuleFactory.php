<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BotRule>
 */
class BotRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'is_active' => true,
            'priority' => 0,
            'scope' => 'both',
            'platforms' => [],
            'match_type' => 'any_keyword',
            'keywords' => [fake()->word()],
            'public_replies' => [fake()->sentence()],
            'action' => 'reply',
            'hits' => 0,
        ];
    }
}
