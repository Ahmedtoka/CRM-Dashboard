<?php

use App\Bot\BotEngine;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Inbox\ConversationActions;
use App\Inbox\OutboundService;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\User;

/** Task 4 ruling 3: priority_level/queue/handover_category clear on resolve or return-to-bot, never on a human reply. */
function routedConversation(): Conversation
{
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::Facebook]);

    return Conversation::factory()->for($cust)->for($acc, 'channelAccount')->create([
        'platform' => Platform::Facebook,
        'handler' => Handler::Human,
        'needs_human' => true,
        'priority_level' => 'high',
        'queue' => 'senior',
        'handover_category' => 'store_complaint',
        'last_customer_message_at' => now()->subMinutes(5),
    ]);
}

it('clears the routing fields when a conversation is resolved', function () {
    $c = routedConversation();
    $u = User::factory()->create(['role' => UserRole::Moderator]);

    app(ConversationActions::class)->resolve($c, $u);

    $c->refresh();
    expect($c->priority_level)->toBeNull()->and($c->queue)->toBeNull()->and($c->handover_category)->toBeNull();
});

it('clears the routing fields when a conversation is returned to the bot', function () {
    $c = routedConversation();
    $u = User::factory()->create(['role' => UserRole::Moderator]);

    app(BotEngine::class)->returnToBot($c, $u);

    $c->refresh();
    expect($c->handler)->toBe(Handler::Bot)
        ->and($c->priority_level)->toBeNull()->and($c->queue)->toBeNull()->and($c->handover_category)->toBeNull();
});

/** Task 5 ruling 6a: stale flow memory must not trigger a repeated/clarify handover when the customer comes back. */
function staleBotState(): array
{
    return [
        'last_turn_message_id' => 4242,
        'clarified' => true,
        'repeat_count' => 3,
        'last_intents' => ['cancel_order'],
        'asks' => ['cancel_order' => 2],
        'collected' => ['phone' => '01000000000'],
    ];
}

it('resets bot_state to only the turn marker when a conversation is resolved', function () {
    $c = routedConversation();
    $c->forceFill(['bot_state' => staleBotState()])->save();

    app(ConversationActions::class)->resolve($c, User::factory()->create(['role' => UserRole::Moderator]));

    expect($c->refresh()->bot_state)->toBe(['last_turn_message_id' => 4242]);
});

it('resets bot_state to only the turn marker when a conversation is returned to the bot', function () {
    $c = routedConversation();
    $c->forceFill(['bot_state' => staleBotState()])->save();

    app(BotEngine::class)->returnToBot($c, User::factory()->create(['role' => UserRole::Moderator]));

    expect($c->refresh()->bot_state)->toBe(['last_turn_message_id' => 4242]);
});

it('nulls bot_state on resolve or return-to-bot when there was no turn marker', function () {
    $u = User::factory()->create(['role' => UserRole::Moderator]);

    $resolved = routedConversation();
    $resolved->forceFill(['bot_state' => ['clarified' => true, 'repeat_count' => 2]])->save();
    app(ConversationActions::class)->resolve($resolved, $u);

    $returned = routedConversation();
    $returned->forceFill(['bot_state' => ['asks' => ['defect' => 1]]])->save();
    app(BotEngine::class)->returnToBot($returned, $u);

    expect($resolved->refresh()->bot_state)->toBeNull()
        ->and($returned->refresh()->bot_state)->toBeNull();
});

it('keeps the routing fields on a plain human reply', function () {
    $c = routedConversation();
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);

    app(OutboundService::class)->sendHuman($c, $mod, 'أهلا بيكي، هساعدك حالًا');

    $c->refresh();
    expect($c->priority_level)->toBe('high')->and($c->queue)->toBe('senior')->and($c->handover_category)->toBe('store_complaint');
});
