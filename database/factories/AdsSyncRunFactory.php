<?php

namespace Database\Factories;

use App\Models\AdAccount;
use App\Models\AdsSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdsSyncRun> */
class AdsSyncRunFactory extends Factory
{
    protected $model = AdsSyncRun::class;

    public function definition(): array
    {
        return [
            'ad_account_id' => AdAccount::factory(),
            'platform' => 'meta',
            'kind' => 'recent',
            'status' => 'ok',
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
