<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\QueueEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QueueEntry>
 */
class QueueEntryFactory extends Factory
{
    protected $model = QueueEntry::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'business_date' => now('Africa/Cairo')->toDateString(),
            'ticket_no' => fake()->unique()->numberBetween(1, 9999),
            'kind' => 'unknown',
            'priority' => 'live',
            'status' => 'waiting',
            'enqueued_at' => now(),
        ];
    }
}
