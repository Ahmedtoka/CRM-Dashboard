<?php

use App\Enums\AttachmentStatus;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Media\SampleMedia;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\QuickReply;
use App\Models\QuickReplyAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Event::fake();
    Storage::fake('media');
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $cust = Customer::factory()->create(['name' => 'Nour Ali']);
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::WhatsApp]);
    $this->conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')
        ->create(['platform' => Platform::WhatsApp, 'handler' => Handler::Human, 'last_customer_message_at' => now()]);
    $this->mod = User::factory()->create(['role' => UserRole::Moderator, 'name' => 'Salma']);
    $this->mod->userPlatforms()->create(['platform' => Platform::WhatsApp]);
    Sanctum::actingAs($this->mod);
});

function mbRender(Conversation $c, QuickReply $r)
{
    return test()->postJson("/api/v1/conversations/{$c->id}/quick-replies/{$r->id}/render");
}

it('fills placeholders and reports the missing ones', function () {
    $reply = QuickReply::factory()->create(['body' => 'أهلاً {customer_first_name}، معاكي {agent_name}. أوردرك {order_number}']);

    mbRender($this->conv, $reply)->assertOk()
        ->assertJsonPath('body', fn (string $b) => str_contains($b, 'أهلاً Nour') && str_contains($b, 'معاكي Salma'))
        ->assertJsonPath('missing', ['order_number'])
        ->assertJsonPath('attachments', []);
});

it('copies the reply attachments into fresh unlinked uploads with signed urls, and they send', function () {
    Storage::disk('media')->put('replies/size.png', SampleMedia::bytes('image'));
    $reply = QuickReply::factory()->create(['body' => 'المقاسات']);
    QuickReplyAttachment::factory()->create(['quick_reply_id' => $reply->id, 'path' => 'replies/size.png', 'mime' => 'image/png', 'type' => 'image']);

    $json = mbRender($this->conv, $reply)->assertOk()->json();

    $copy = MessageAttachment::find($json['attachments'][0]['id']);
    expect($copy->uploaded_by)->toBe($this->mod->id)
        ->and($copy->message_id)->toBeNull()
        ->and($copy->status)->toBe(AttachmentStatus::Stored)
        ->and($copy->path)->not->toBe('replies/size.png')
        ->and($json['attachments'][0]['url'])->toStartWith(url('/media/s/'.$copy->id))->toContain('signature=');

    $this->postJson("/api/v1/conversations/{$this->conv->id}/messages", [
        'body' => $json['body'], 'attachment_ids' => [$copy->id], 'quick_reply_id' => $reply->id,
    ])->assertCreated();

    expect($copy->fresh()->message_id)->not->toBeNull()
        ->and(Message::query()->where('conversation_id', $this->conv->id)->count())->toBeGreaterThan(0)
        ->and($reply->fresh()->use_count)->toBe(1);
});

it('forbids a reply restricted to another platform', function () {
    $reply = QuickReply::factory()->create(['platforms' => ['facebook']]);

    mbRender($this->conv, $reply)->assertForbidden();
});

it('forbids someone else\'s personal reply', function () {
    $reply = QuickReply::factory()->personal(User::factory()->create())->create();

    mbRender($this->conv, $reply)->assertForbidden();
});

it('forbids a conversation on a platform she does not work', function () {
    $fb = Conversation::factory()->for(ChannelAccount::factory()->create(['platform' => Platform::Facebook]), 'channelAccount')
        ->create(['platform' => Platform::Facebook]);
    $reply = QuickReply::factory()->create(['platforms' => []]);

    mbRender($fb, $reply)->assertForbidden();
});
