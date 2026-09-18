<?php

use App\Bot\BotEngine;
use App\Enums\ConversationPriority;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Events\ConversationUpdated;
use App\Events\MessageCreated;
use App\Events\UserNotified;
use App\Inbox\UserNotifier;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => Event::fake([UserNotified::class, ConversationUpdated::class, MessageCreated::class]));

it('stores and validates notification preferences with defaults', function () {
    $u = User::factory()->create();
    expect($u->notificationPreferences())->toBe(User::DEFAULT_PREFERENCES);

    $this->actingAs($u)->patchJson('/settings/notifications', ['sound' => false, 'notify_scope' => 'mine_and_handover', 'sound_volume' => 0.3])
        ->assertOk()->assertJsonPath('data.sound', false)->assertJsonPath('data.desktop_notifications', false);
    $this->actingAs($u)->patchJson('/settings/notifications', ['notify_scope' => 'everyone'])->assertStatus(422);
    $this->actingAs($u)->get('/settings/notifications')->assertInertia(fn ($p) => $p->component('settings/Notifications')->where('preferences.sound_volume', 0.3));
});

it('persists notifications, broadcasts them and marks them read', function () {
    $u = User::factory()->create();
    $n = app(UserNotifier::class)->notify($u, 'note.mention', ['conversation_id' => 5]);

    Event::assertDispatched(UserNotified::class, fn ($e) => $e->userId === $u->id && $e->data['id'] === $n->id);
    $this->actingAs($u)->getJson('/notifications')->assertOk()->assertJsonPath('unread_notifications', 1)->assertJsonPath('data.0.type', 'note.mention');
    $this->actingAs($u)->postJson('/notifications/read')->assertOk()->assertJsonPath('unread_notifications', 0);
    expect($n->fresh()->read_at)->not->toBeNull();
});

it('never lets a user mark another user\'s notification as read or see it in their own list (IDOR)', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $n = app(UserNotifier::class)->notify($owner, 'note.mention', ['conversation_id' => 5]);

    $this->actingAs($intruder)->postJson('/notifications/read', ['ids' => [$n->id]])->assertOk();

    expect($n->fresh()->read_at)->toBeNull();
    $this->actingAs($intruder)->getJson('/notifications')->assertOk()->assertJsonPath('data', [])->assertJsonPath('unread_notifications', 0);
    $this->actingAs($owner)->getJson('/notifications')->assertOk()->assertJsonPath('unread_notifications', 1);
});

it('counts visible unread conversations for the tab badge', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    Conversation::factory()->for(ChannelAccount::factory()->create(['platform' => Platform::Facebook]), 'channelAccount')->create(['unread_count' => 2]);
    Conversation::factory()->for(ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]), 'channelAccount')->create(['unread_count' => 4]);

    $this->actingAs($mod)->getJson('/notifications')->assertJsonPath('unread_conversations', 1);
});

it('excludes spam and low-priority conversations from the tab badge, matching MetricsService', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    Conversation::factory()->for(ChannelAccount::factory()->create(['platform' => Platform::Facebook]), 'channelAccount')
        ->create(['unread_count' => 2, 'priority' => ConversationPriority::Normal]);
    Conversation::factory()->for(ChannelAccount::factory()->create(['platform' => Platform::Facebook]), 'channelAccount')
        ->create(['unread_count' => 3, 'priority' => ConversationPriority::Spam]);
    Conversation::factory()->for(ChannelAccount::factory()->create(['platform' => Platform::Facebook]), 'channelAccount')
        ->create(['unread_count' => 5, 'priority' => ConversationPriority::Low]);

    $this->actingAs($mod)->getJson('/notifications')->assertJsonPath('unread_conversations', 1);
});

it('persists handover notifications for users who can access the platform', function () {
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $conv = Conversation::factory()->for($acc, 'channelAccount')->create(['handler' => Handler::Bot]);
    $ig = User::factory()->create(['role' => UserRole::Moderator]);
    $ig->userPlatforms()->create(['platform' => Platform::Instagram]);
    $wa = User::factory()->create(['role' => UserRole::Moderator]);
    $wa->userPlatforms()->create(['platform' => Platform::WhatsApp]);

    app(BotEngine::class)->handover($conv, 'keyword');

    expect(UserNotification::where('user_id', $ig->id)->where('type', 'conversation.handover')->exists())->toBeTrue()
        ->and(UserNotification::where('user_id', $wa->id)->exists())->toBeFalse()
        ->and(UserNotification::where('user_id', $ig->id)->where('type', 'conversation.handover')->count())->toBe(1);
});

it('does not notify an inactive user of a handover even when their platform matches', function () {
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $conv = Conversation::factory()->for($acc, 'channelAccount')->create(['handler' => Handler::Bot]);
    $inactive = User::factory()->create(['role' => UserRole::Moderator, 'is_active' => false]);
    $inactive->userPlatforms()->create(['platform' => Platform::Instagram]);

    app(BotEngine::class)->handover($conv, 'keyword');

    expect(UserNotification::where('user_id', $inactive->id)->exists())->toBeFalse();
});

it('adds conversation context to inbound message broadcasts', function () {
    $conv = Conversation::factory()->create(['priority' => ConversationPriority::Normal]);
    $m = Message::factory()->create(['conversation_id' => $conv->id, 'direction' => 'in', 'sender_type' => 'customer']);

    expect(MessageCreated::payload($m->fresh())['conversation'])
        ->toMatchArray(['platform' => $conv->platform->value, 'customer_name' => $conv->customer->name, 'handler' => 'bot', 'priority' => 'normal']);
});
