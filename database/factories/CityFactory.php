<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\City>
 */
class CityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name_ar' => fake()->city(),
            'name_en' => fake()->city(),
            'shipping_fee' => fake()->randomFloat(2, 30, 100),
        ];
    }
}
