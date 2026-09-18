<?php

use App\Enums\AttachmentType;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;

it('lists stored conversation media for users who can see it', function () {
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $conv = Conversation::factory()->for($acc, 'channelAccount')->create();
    $msg = Message::factory()->create(['conversation_id' => $conv->id]);
    $stored = MessageAttachment::factory()->stored()->create(['message_id' => $msg->id]);
    MessageAttachment::factory()->create(['message_id' => $msg->id]); // pending: hidden
    MessageAttachment::factory()->stored()->create(); // other conversation

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->getJson("/inbox/conversations/{$conv->id}/media")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $stored->id);

    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))
        ->getJson("/inbox/conversations/{$conv->id}/media")->assertForbidden();
});

it('excludes stickers and failed rows, and orders stored media newest first', function () {
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $conv = Conversation::factory()->for($acc, 'channelAccount')->create();
    $msg = Message::factory()->create(['conversation_id' => $conv->id]);

    MessageAttachment::factory()->stored()->create(['message_id' => $msg->id, 'type' => AttachmentType::Sticker]);
    MessageAttachment::factory()->failed()->create(['message_id' => $msg->id]);

    $ids = collect(range(1, 3))
        ->map(fn () => MessageAttachment::factory()->stored()->create(['message_id' => $msg->id])->id)
        ->all();

    $response = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->getJson("/inbox/conversations/{$conv->id}/media")->assertOk();

    $response->assertJsonCount(3, 'data');
    expect($response->json('data.*.id'))->toBe(array_reverse($ids));
});

it('caps the media list at 200 rows', function () {
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $conv = Conversation::factory()->for($acc, 'channelAccount')->create();
    $msg = Message::factory()->create(['conversation_id' => $conv->id]);

    MessageAttachment::factory()->count(205)->stored()->create(['message_id' => $msg->id]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->getJson("/inbox/conversations/{$conv->id}/media")->assertOk()
        ->assertJsonCount(200, 'data');
});
