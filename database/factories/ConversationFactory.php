<?php

namespace Database\Factories;

use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Models\ChannelAccount;
use App\Models\Customer;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'channel_account_id' => ChannelAccount::factory(),
            'status' => ConversationStatus::Open,
            'handler' => Handler::Bot,
            'needs_human' => false,
            'source' => ConversationSource::Direct,
            'unread_count' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Conversation $conversation) {
            $account = $conversation->channel_account_id
                ? ChannelAccount::find($conversation->channel_account_id)
                : null;

            if ($account) {
                $conversation->platform = $account->platform;
            }
        });
    }
}
