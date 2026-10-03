<?php

namespace Database\Factories;

use App\Models\Ad;
use App\Models\AdAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ad> */
class AdFactory extends Factory
{
    protected $model = Ad::class;

    public function definition(): array
    {
        return [
            'ad_account_id' => AdAccount::factory(),
            'ad_campaign_id' => null,
            'ad_set_id' => null,
            'external_id' => fake()->unique()->numerify('###########'),
            'name' => fake()->words(3, true),
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'type' => 'image',
        ];
    }
}
