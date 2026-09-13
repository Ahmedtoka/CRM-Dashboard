<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->words(3, true);

        return [
            'shopify_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'title' => $title,
            'handle' => Str::slug($title),
            'status' => 'active',
        ];
    }
}
