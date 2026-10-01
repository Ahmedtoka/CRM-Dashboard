<?php

namespace Database\Factories;

use App\Models\BuyerTarget;
use App\Models\MediaBuyer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BuyerTarget> */
class BuyerTargetFactory extends Factory
{
    protected $model = BuyerTarget::class;

    public function definition(): array
    {
        return [
            'media_buyer_id' => MediaBuyer::factory(),
            'month' => now()->startOfMonth()->toDateString(),
            'budget' => 50000,
            'target_roas' => 2.5,
        ];
    }
}
