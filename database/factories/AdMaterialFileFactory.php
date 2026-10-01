<?php

namespace Database\Factories;

use App\Models\AdMaterial;
use App\Models\AdMaterialFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdMaterialFile> */
class AdMaterialFileFactory extends Factory
{
    protected $model = AdMaterialFile::class;

    public function definition(): array
    {
        return [
            'ad_material_id' => AdMaterial::factory(),
            'disk' => 'public',
            'path' => 'ads/materials/'.fake()->uuid().'.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
            'sort' => 0,
        ];
    }
}
