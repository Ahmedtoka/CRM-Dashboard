<?php

namespace Database\Factories;

use App\Enums\ParticipantRole;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ConversationParticipant>
 */
class ConversationParticipantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'role' => ParticipantRole::First,
            'messages_count' => 1,
            'first_message_at' => now(),
            'last_message_at' => now(),
        ];
    }
}
