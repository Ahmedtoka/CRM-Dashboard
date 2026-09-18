<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'governorate' => 'القاهرة',
            'area_key' => 'nasr_city',
            'area_ar' => 'مدينة نصر',
            'area_en' => 'Nasr City',
            'name' => $this->faker->company(),
            'address' => $this->faker->streetAddress(),
            'phone' => '010'.$this->faker->numerify('########'),
            'map_url' => 'https://goo.gl/maps/'.$this->faker->bothify('??????????'),
            'hours' => null,
            'aliases' => ['مدينة نصر', 'nasr city'],
            'is_active' => true,
            'sort' => 10,
        ];
    }
}
