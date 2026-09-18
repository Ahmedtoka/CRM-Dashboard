<?php

use App\Enums\ConversationPriority;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\User;

/**
 * Task 5 fix round 1: the inbox list API returns the queue filters in the order the
 * frontend mirrors in resources/js/lib/inboxListOrder.ts (priority high → medium → low
 * → none, then oldest customer message, then id) and the same membership rules.
 */
function apiQueueConversation(array $attributes): Conversation
{
    return Conversation::factory()->for(ChannelAccount::factory()->state(['platform' => Platform::Facebook]), 'channelAccount')->create(array_merge([
        'platform' => Platform::Facebook,
        'needs_human' => true,
        'queue' => 'agents',
        'handover_category' => 'cancel_order',
    ], $attributes));
}

it('returns the queue filters over the API in priority order, oldest customer message first, id as tiebreak', function (string $filter) {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $at = now()->subMinutes(15);

    $none = apiQueueConversation(['priority_level' => null, 'last_customer_message_at' => now()->subHour()]);
    $mediumOld = apiQueueConversation(['priority_level' => 'medium', 'last_customer_message_at' => now()->subMinutes(40)]);
    $highNew = apiQueueConversation(['priority_level' => 'high', 'last_customer_message_at' => now()->subMinutes(5)]);
    $highTieA = apiQueueConversation(['priority_level' => 'high', 'last_customer_message_at' => $at]);
    $highTieB = apiQueueConversation(['priority_level' => 'high', 'last_customer_message_at' => $at]);
    $low = apiQueueConversation(['priority_level' => 'low', 'last_customer_message_at' => now()->subHours(2)]);

    $ids = collect($this->actingAs($sup)->getJson("/inbox/conversations?filter={$filter}")->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$highTieA->id, $highTieB->id, $highNew->id, $mediumOld->id, $low->id, $none->id]);
})->with(['needs_human', 'queue_all']);

it('applies the queue membership rules the client mirrors: needs_human, high, senior, no low-value or spam', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);

    $highSenior = apiQueueConversation(['priority_level' => 'high', 'queue' => 'senior', 'last_customer_message_at' => now()->subMinutes(9)]);
    $highAgents = apiQueueConversation(['priority_level' => 'high', 'last_customer_message_at' => now()->subMinutes(8)]);
    $mediumSenior = apiQueueConversation(['priority_level' => 'medium', 'queue' => 'senior', 'last_customer_message_at' => now()->subMinutes(7)]);
    $returnedToBot = apiQueueConversation(['needs_human' => false, 'priority_level' => 'high', 'queue' => 'senior', 'last_customer_message_at' => now()->subMinutes(6)]);
    $lowValue = apiQueueConversation(['priority_level' => 'high', 'priority' => ConversationPriority::Low, 'last_customer_message_at' => now()->subMinutes(5)]);
    $spam = apiQueueConversation(['priority_level' => 'high', 'queue' => 'senior', 'priority' => ConversationPriority::Spam, 'last_customer_message_at' => now()->subMinutes(4)]);

    $list = fn (User $u, string $filter) => collect($this->actingAs($u)->getJson("/inbox/conversations?filter={$filter}")->assertOk()->json('data'))->pluck('id')->all();

    expect($list($sup, 'queue_all'))->toBe([$highSenior->id, $highAgents->id, $mediumSenior->id])
        ->and($list($sup, 'queue_high'))->toBe([$highSenior->id, $highAgents->id])
        ->and($list($sup, 'queue_senior'))->toBe([$highSenior->id, $mediumSenior->id])
        ->and($list($mod, 'queue_senior'))->toBe([]);

    expect($list($sup, 'queue_all'))->not->toContain($returnedToBot->id, $lowValue->id, $spam->id);
});
