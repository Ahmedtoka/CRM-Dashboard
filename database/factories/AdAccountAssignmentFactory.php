<?php

namespace Database\Factories;

use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\MediaBuyer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdAccountAssignment> */
class AdAccountAssignmentFactory extends Factory
{
    protected $model = AdAccountAssignment::class;

    public function definition(): array
    {
        return [
            'ad_account_id' => AdAccount::factory(),
            'media_buyer_id' => MediaBuyer::factory(),
            'starts_on' => '2026-09-01',
            'ends_on' => null,
        ];
    }
}
