<?php

namespace Database\Factories;

use App\Models\AdMaterialCollection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdMaterialCollection> */
class AdMaterialCollectionFactory extends Factory
{
    protected $model = AdMaterialCollection::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'is_active' => true,
            'sort' => 0,
        ];
    }
}
