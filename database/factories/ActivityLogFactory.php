<?php

namespace Database\Factories;

use App\Enums\ActorType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'actor_type' => ActorType::User,
            'user_id' => User::factory(),
            'action' => 'message.sent',
        ];
    }
}
