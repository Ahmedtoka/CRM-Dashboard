<?php

use App\Enums\Platform;
use App\Enums\UserRole;
use App\Inbox\ConversationQuery;
use App\Models\Conversation;
use App\Models\User;

function makeQueueConversation(string $priorityLevel, string $queue, $lastCustomerMessageAt): Conversation
{
    return Conversation::factory()->create([
        'platform' => Platform::Facebook,
        'needs_human' => true,
        'priority_level' => $priorityLevel,
        'queue' => $queue,
        'handover_category' => 'cancel_order',
        'last_customer_message_at' => $lastCustomerMessageAt,
    ]);
}

it('orders needs_human and the queue filters by priority then oldest message first', function (string $filter) {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $medium = makeQueueConversation('medium', 'agents', now()->subMinutes(20));
    $high = makeQueueConversation('high', 'agents', now()->subMinutes(10));
    $low = makeQueueConversation('low', 'agents', now()->subMinutes(30));

    $ids = app(ConversationQuery::class)->build($sup, ['filter' => $filter])->pluck('id')->all();

    expect($ids)->toBe([$high->id, $medium->id, $low->id]);
})->with(['needs_human', 'queue_all']);

it('queue_high only returns high priority conversations needing a human', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $high = makeQueueConversation('high', 'agents', now()->subMinutes(10));
    makeQueueConversation('medium', 'agents', now()->subMinutes(5));

    $ids = app(ConversationQuery::class)->build($sup, ['filter' => 'queue_high'])->pluck('id')->all();

    expect($ids)->toBe([$high->id]);
});

it('restricts queue_senior to supervisors and admins, returning nothing for a moderator', function () {
    $senior = makeQueueConversation('high', 'senior', now()->subMinutes(15));
    makeQueueConversation('medium', 'agents', now()->subMinutes(5));

    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);

    $supIds = app(ConversationQuery::class)->build($sup, ['filter' => 'queue_senior'])->pluck('id')->all();
    $modIds = app(ConversationQuery::class)->build($mod, ['filter' => 'queue_senior'])->pluck('id')->all();

    expect($supIds)->toBe([$senior->id])->and($modIds)->toBe([]);
});
