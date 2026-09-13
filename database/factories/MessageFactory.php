<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'direction' => MessageDirection::Out,
            'sender_type' => SenderType::Bot,
            'body' => fake()->sentence(),
            'status' => MessageStatus::Sent,
            'is_template' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Message $message) {
            $conversation = $message->conversation_id
                ? Conversation::find($message->conversation_id)
                : null;

            if ($conversation && $message->platform === null) {
                $message->platform = $conversation->platform;
            }
        });
    }
}
