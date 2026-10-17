<?php

namespace Database\Factories;

use App\Models\ScoreEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScoreEvent> */
class ScoreEventFactory extends Factory
{
    protected $model = ScoreEvent::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'business_date' => now('Africa/Cairo')->toDateString(),
            'kind' => 'base',
            'points' => 8,
            'requested' => 8,
            'reason_key' => 'base_inquiry',
            'reason_vars' => ['ticket' => 1],
            'unique_key' => 'test:'.fake()->unique()->uuid(),
        ];
    }
}
