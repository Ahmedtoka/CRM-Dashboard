<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        $day = now('Africa/Cairo')->startOfDay();

        return [
            'date' => $day->toDateString(),
            'shift_key' => 'morning',
            'name' => 'صباحي',
            'starts_at' => $day->copy()->setTime(10, 0)->utc(),
            'ends_at' => $day->copy()->setTime(18, 0)->utc(),
            'status' => 'open',
            'opened_at' => now(),
            'leader_user_id' => User::factory()->state(['role' => 'supervisor']),
        ];
    }
}
