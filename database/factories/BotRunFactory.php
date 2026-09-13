<?php

namespace Database\Factories;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BotRun>
 */
class BotRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'trigger_message' => fake()->sentence(),
            'engine' => 'rule',
            'decision' => 'reply',
            'reply_text' => fake()->sentence(),
        ];
    }
}
