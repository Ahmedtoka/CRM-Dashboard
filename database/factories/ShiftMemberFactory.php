<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ShiftMember>
 */
class ShiftMemberFactory extends Factory
{
    protected $model = ShiftMember::class;

    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'user_id' => User::factory(),
            'status' => 'available',
            'joined_at' => now(),
        ];
    }
}
