<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QuickReply>
 */
class QuickReplyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shortcut' => '/'.fake()->unique()->word(),
            'title' => fake()->words(3, true),
            'body' => fake()->sentence(),
            'platforms' => [],
            'created_by' => User::factory(),
        ];
    }
}
