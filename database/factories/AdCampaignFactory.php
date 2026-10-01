<?php

namespace Database\Factories;

use App\Models\AdAccount;
use App\Models\AdCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdCampaign> */
class AdCampaignFactory extends Factory
{
    protected $model = AdCampaign::class;

    public function definition(): array
    {
        return [
            'ad_account_id' => AdAccount::factory(),
            'external_id' => fake()->unique()->numerify('###########'),
            'name' => fake()->words(3, true),
            'status' => 'ACTIVE',
            'objective' => 'OUTCOME_SALES',
        ];
    }
}
