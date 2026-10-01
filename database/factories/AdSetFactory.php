<?php

namespace Database\Factories;

use App\Models\AdCampaign;
use App\Models\AdSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdSet> */
class AdSetFactory extends Factory
{
    protected $model = AdSet::class;

    public function definition(): array
    {
        return [
            'ad_campaign_id' => AdCampaign::factory(),
            'external_id' => fake()->unique()->numerify('###########'),
            'name' => fake()->words(3, true),
            'status' => 'ACTIVE',
        ];
    }
}
