<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\QuickReply;
use App\Models\QuickReplyUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuickReplyUsage>
 */
class QuickReplyUsageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quick_reply_id' => QuickReply::factory(),
            'user_id' => User::factory(),
            'conversation_id' => null,
            'platform' => Platform::Facebook,
            'used_at' => now(),
        ];
    }
}
