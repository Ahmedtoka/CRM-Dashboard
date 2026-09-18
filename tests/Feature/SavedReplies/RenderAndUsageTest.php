<?php

use App\Enums\{AttachmentStatus, Handler, Platform, UserRole};
use App\Inbox\SavedReplies\AttachmentCopier;
use App\Media\MediaRejected;
use App\Models\{ChannelAccount, Conversation, Customer, CustomerIdentity, Message, MessageAttachment, QuickReply, QuickReplyAttachment, QuickReplyUsage, User};
use Illuminate\Support\Facades\{Event, Storage};

beforeEach(function () {
    Event::fake();
    Storage::fake('media');
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $cust = Customer::factory()->create(['name' => 'Nour Ali']);
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::WhatsApp]);
    $this->conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')->create(['handler' => Handler::Human, 'last_customer_message_at' => now()]);
    $this->mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->mod->userPlatforms()->create(['platform' => Platform::WhatsApp]);
});

it('renders a reply and copies its attachments into fresh uploads', function () {
    Storage::disk('media')->put('replies/size.png', App\Media\SampleMedia::bytes('image'));
    $reply = QuickReply::factory()->create(['body' => 'أهلاً {customer_first_name}']);
    QuickReplyAttachment::factory()->create(['quick_reply_id' => $reply->id, 'path' => 'replies/size.png', 'mime' => 'image/png', 'type' => 'image']);

    $json = $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/quick-replies/{$reply->id}/render")
        ->assertOk()->assertJsonPath('body', 'أهلاً Nour')->assertJsonPath('missing', [])->json();

    $copy = MessageAttachment::find($json['attachments'][0]['id']);
    expect($copy->uploaded_by)->toBe($this->mod->id)->and($copy->message_id)->toBeNull()
        ->and($copy->status)->toBe(AttachmentStatus::Stored)->and($copy->path)->not->toBe('replies/size.png');
    Storage::disk('media')->assertExists($copy->path);
});

it('forbids rendering someone else\'s personal reply', function () {
    $reply = QuickReply::factory()->personal(User::factory()->create())->create();
    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/quick-replies/{$reply->id}/render")->assertForbidden();
});

it('records usage when a message created from a reply is sent', function () {
    $reply = QuickReply::factory()->create();

    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/messages", ['body' => 'hi', 'quick_reply_id' => $reply->id])->assertCreated();

    expect($reply->fresh()->use_count)->toBe(1)->and($reply->fresh()->last_used_at)->not->toBeNull()
        ->and(QuickReplyUsage::where(['quick_reply_id' => $reply->id, 'user_id' => $this->mod->id, 'conversation_id' => $this->conv->id, 'platform' => 'whatsapp'])->exists())->toBeTrue();
});

it('forbids rendering a reply restricted to another platform (spec §2.3)', function () {
    $reply = QuickReply::factory()->create(['platforms' => ['facebook']]);

    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/quick-replies/{$reply->id}/render")->assertForbidden();
});

it('refuses a send whose quick_reply_id is restricted to another platform, sends and records nothing', function () {
    $reply = QuickReply::factory()->create(['platforms' => ['facebook']]);

    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/messages", ['body' => 'hi', 'quick_reply_id' => $reply->id])
        ->assertForbidden();

    expect(Message::count())->toBe(0)->and($reply->fresh()->use_count)->toBe(0)
        ->and(QuickReplyUsage::where('quick_reply_id', $reply->id)->exists())->toBeFalse();
});

it('refuses a send whose quick_reply_id is someone else\'s personal reply, sends and records nothing', function () {
    $reply = QuickReply::factory()->personal(User::factory()->create())->create();

    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/messages", ['body' => 'hi', 'quick_reply_id' => $reply->id])
        ->assertForbidden();

    expect(Message::count())->toBe(0)->and($reply->fresh()->use_count)->toBe(0)
        ->and(QuickReplyUsage::where('quick_reply_id', $reply->id)->exists())->toBeFalse();
});

it('rendering the same reply twice copies two independent attachments', function () {
    Storage::disk('media')->put('replies/twice.png', App\Media\SampleMedia::bytes('image'));
    $reply = QuickReply::factory()->create();
    QuickReplyAttachment::factory()->create(['quick_reply_id' => $reply->id, 'path' => 'replies/twice.png', 'mime' => 'image/png', 'type' => 'image']);

    $first = $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/quick-replies/{$reply->id}/render")->assertOk()->json();
    $second = $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/quick-replies/{$reply->id}/render")->assertOk()->json();

    $firstCopy = MessageAttachment::find($first['attachments'][0]['id']);
    $secondCopy = MessageAttachment::find($second['attachments'][0]['id']);

    expect($firstCopy->id)->not->toBe($secondCopy->id)->and($firstCopy->path)->not->toBe($secondCopy->path);
    Storage::disk('media')->assertExists($firstCopy->path);
    Storage::disk('media')->assertExists($secondCopy->path);
});

it('GET /quick-reply-attachments/{id} serves an authorised attachment with sandboxed CSP and a private cache', function () {
    Storage::disk('media')->put('replies/thumb.png', App\Media\SampleMedia::bytes('image'));
    $reply = QuickReply::factory()->create();
    $attachment = QuickReplyAttachment::factory()->create(['quick_reply_id' => $reply->id, 'path' => 'replies/thumb.png', 'mime' => 'image/png', 'type' => 'image']);

    $response = $this->actingAs($this->mod)->get("/quick-reply-attachments/{$attachment->id}");

    $response->assertOk()
        ->assertHeader('Content-Security-Policy', 'sandbox')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((string) $response->headers->get('Cache-Control'))->toContain('private')->not->toContain('public');
});

it('GET /quick-reply-attachments/{id} forbids another user\'s personal reply attachment', function () {
    $reply = QuickReply::factory()->personal(User::factory()->create())->create();
    $attachment = QuickReplyAttachment::factory()->create(['quick_reply_id' => $reply->id]);

    $this->actingAs($this->mod)->get("/quick-reply-attachments/{$attachment->id}")->assertForbidden();
});

it('cleans up and throws when the media disk fails to write the copy', function () {
    $reply = QuickReply::factory()->create();
    $source = QuickReplyAttachment::factory()->create(['quick_reply_id' => $reply->id, 'path' => 'replies/fail.png', 'mime' => 'image/png', 'type' => 'image']);

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'not-really-a-png');
    rewind($stream);

    $disk = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    $disk->shouldReceive('readStream')->once()->andReturn($stream);
    $disk->shouldReceive('writeStream')->once()->andReturn(false);
    $disk->shouldReceive('delete')->once()->andReturn(true);
    Storage::shouldReceive('disk')->with('media')->andReturn($disk);

    expect(fn () => app(AttachmentCopier::class)->toOutbound($source, $this->mod))->toThrow(MediaRejected::class);

    expect(MessageAttachment::count())->toBe(0);
});
