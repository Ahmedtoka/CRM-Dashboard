<?php

namespace Database\Factories;

use App\Models\Ad;
use App\Models\AdDailyMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdDailyMetric> */
class AdDailyMetricFactory extends Factory
{
    protected $model = AdDailyMetric::class;

    public function definition(): array
    {
        return [
            'ad_id' => Ad::factory(),
            'ad_account_id' => fn (array $a) => Ad::query()->findOrFail($a['ad_id'])->ad_account_id,
            'date' => now()->subDay()->toDateString(),
            'spend' => 100,
            'impressions' => 1000,
            'clicks' => 20,
            'reach' => 800,
            'purchases' => 2,
            'purchase_value' => 400,
        ];
    }
}
