<?php

namespace Database\Factories;

use App\Models\QuickReplyCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuickReplyCategory>
 */
class QuickReplyCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'sort' => 100,
        ];
    }
}
