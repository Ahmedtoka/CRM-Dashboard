<?php

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

function s3WaitingChat(ChannelAccount $acc, int $minutes, bool $answered): Conversation
{
    $c = Conversation::factory()->for($acc, 'channelAccount')->create([
        'platform' => $acc->platform, 'last_customer_message_at' => now()->subMinutes($minutes), 'last_message_at' => now()->subMinutes($minutes),
    ]);
    Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'created_at' => now()->subMinutes($minutes)]);
    if ($answered) {
        Message::factory()->for($c)->create(['direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'created_at' => now()->subMinutes($minutes - 1)]);
    }

    return $c;
}

it('lists only waiting chats, oldest first, with sort=oldest_waiting', function () {
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $newer = s3WaitingChat($acc, 5, false);
    $older = s3WaitingChat($acc, 50, false);
    $answered = s3WaitingChat($acc, 90, true);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $ids = $this->actingAs($admin)->getJson('/inbox/conversations?sort=oldest_waiting')->assertOk()->json('data.*.id');

    expect($ids)->toBe([$older->id, $newer->id])->not->toContain($answered->id);
    $this->actingAs($admin)->getJson('/inbox/conversations?sort=nope')->assertUnprocessable();
});

it('keeps the counts independent of the sort', function () {
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    s3WaitingChat($acc, 5, false);
    s3WaitingChat($acc, 90, true);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $plain = $this->actingAs($admin)->getJson('/inbox/conversations/counts')->assertOk()->json();
    $sorted = $this->actingAs($admin)->getJson('/inbox/conversations/counts?sort=oldest_waiting')->assertOk()->json();

    expect($sorted)->toBe($plain);
});

it('shares the sort in the inbox page filters', function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/inbox?sort=oldest_waiting')->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.sort', 'oldest_waiting'));
});
