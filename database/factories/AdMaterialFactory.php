<?php

namespace Database\Factories;

use App\Models\AdMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdMaterial> */
class AdMaterialFactory extends Factory
{
    protected $model = AdMaterial::class;

    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'types' => ['reel'],
            'status' => 'new',
            'website_links' => [],
            'drive_links' => [],
            'ig_links' => [],
        ];
    }
}
