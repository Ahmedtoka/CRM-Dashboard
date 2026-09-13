<?php

namespace Database\Factories;

use App\Enums\CommentStatus;
use App\Models\Customer;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Comment>
 */
class CommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'customer_id' => Customer::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'body' => fake()->sentence(),
            'status' => CommentStatus::New,
        ];
    }
}
