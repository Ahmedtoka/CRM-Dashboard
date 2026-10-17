<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\QaReview;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QaReview> */
class QaReviewFactory extends Factory
{
    protected $model = QaReview::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'queue_entry_id' => QueueEntry::factory(),
            'conversation_id' => Conversation::factory(),
            'business_date' => now('Africa/Cairo')->toDateString(),
            'score' => 4,
            'checklist' => array_fill_keys(QaReview::CHECKLIST, true),
        ];
    }
}
