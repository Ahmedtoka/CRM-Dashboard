<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Inbox\OutboundService;
use App\Media\MediaPolicy;
use App\Media\SampleMedia;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Event::fake();
    Storage::fake('media');
    $this->platformConv = function (Platform $p): Conversation {
        $acc = ChannelAccount::factory()->create(['platform' => $p]);
        $cust = Customer::factory()->create();
        CustomerIdentity::factory()->for($cust)->create(['platform' => $p]);

        return Conversation::factory()->for($cust)->for($acc, 'channelAccount')->create(['handler' => Handler::Human, 'last_customer_message_at' => now()->subMinute()]);
    };
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

function uploadSample(string $kind, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($path, SampleMedia::bytes($kind));

    return new UploadedFile($path, $name, SampleMedia::mime($kind), null, true);
}

it('uploads, then sends one message per attachment with the caption on the first', function () {
    $conv = ($this->platformConv)(Platform::Facebook);
    $ids = collect([['image', 'a.png'], ['file', 'b.pdf']])->map(fn ($f) => $this->actingAs($this->admin)
        ->post("/inbox/conversations/{$conv->id}/attachments", ['file' => uploadSample(...$f)], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.status', 'stored')->json('data.id'))->all();

    $response = $this->actingAs($this->admin)->postJson("/inbox/conversations/{$conv->id}/messages", ['body' => 'ده الموديل', 'attachment_ids' => $ids])
        ->assertCreated()->assertJsonCount(2, 'messages');

    $messages = Message::where('conversation_id', $conv->id)->orderBy('id')->get();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]->body)->toBe('ده الموديل')->and($messages[1]->body)->toBeNull()
        ->and($messages->every(fn ($m) => $m->status === MessageStatus::Sent))->toBeTrue()
        ->and(MessageAttachment::find($ids[0])->message_id)->toBe($messages[0]->id)
        ->and($response->json('data.attachments.0.id'))->toBe($ids[0]);

    $sent = collect(FakeChannelAdapter::sent())->where('method', 'sendAttachment')->values();
    expect($sent)->toHaveCount(2)->and($sent[0]['caption'])->toBe('ده الموديل')->and($sent[1]['caption'])->toBeNull();
});

it('refuses attachments uploaded by someone else or already sent', function () {
    $conv = ($this->platformConv)(Platform::Facebook);
    $foreign = MessageAttachment::factory()->stored()->create(['message_id' => null, 'uploaded_by' => User::factory()->create()->id]);

    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$conv->id}/messages", ['attachment_ids' => [$foreign->id]])
        ->assertStatus(422)->assertJsonPath('message', MediaPolicy::NOT_CLAIMABLE);
    expect(Message::count())->toBe(0);
});

it('rejects generic files on instagram before sending', function () {
    $conv = ($this->platformConv)(Platform::Instagram);
    $pdf = MessageAttachment::factory()->stored()->create(['message_id' => null, 'uploaded_by' => $this->admin->id, 'type' => 'file', 'mime' => 'application/pdf', 'size_bytes' => 100]);

    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$conv->id}/messages", ['attachment_ids' => [$pdf->id]])
        ->assertStatus(422)->assertJsonPath('message', MediaPolicy::INSTAGRAM_FILE);
});

it('rejects reusing an attachment id already linked to another message', function () {
    $conv = ($this->platformConv)(Platform::Facebook);
    // stored() leaves the factory's default message_id (Message::factory()) in place,
    // i.e. this attachment is already sent/claimed by a different message.
    $already = MessageAttachment::factory()->stored()->create(['uploaded_by' => $this->admin->id]);

    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$conv->id}/messages", ['attachment_ids' => [$already->id]])
        ->assertStatus(422)->assertJsonPath('message', MediaPolicy::NOT_CLAIMABLE);
    expect(Message::where('conversation_id', $conv->id)->count())->toBe(0);
});

it('refuses to combine attachment_ids with a template', function () {
    $conv = ($this->platformConv)(Platform::WhatsApp);
    $attachment = MessageAttachment::factory()->stored()->create(['message_id' => null, 'uploaded_by' => $this->admin->id]);

    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$conv->id}/messages", [
        'attachment_ids' => [$attachment->id],
        'template' => ['name' => 'order_update', 'language' => 'ar', 'params' => ['x', 'y']],
    ])->assertStatus(422)->assertJsonValidationErrors(['attachment_ids']);

    expect(Message::count())->toBe(0);
    expect(MessageAttachment::find($attachment->id)->message_id)->toBeNull();
});

it('refuses attachments outside the reply window and creates no rows', function () {
    // WhatsApp: windowHours 24, humanAgentHours 0 — 30h since the last customer
    // message lands in template_only, exactly like a free-form text send would.
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::WhatsApp]);
    $conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')
        ->create(['handler' => Handler::Human, 'last_customer_message_at' => now()->subHours(30)]);
    $attachment = MessageAttachment::factory()->stored()->create(['message_id' => null, 'uploaded_by' => $this->admin->id]);

    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$conv->id}/messages", ['attachment_ids' => [$attachment->id]])
        ->assertStatus(422)->assertJsonPath('mode', 'template_only');

    expect(Message::count())->toBe(0);
    expect(MessageAttachment::find($attachment->id)->message_id)->toBeNull();
});

it('tags an attachment send with HUMAN_AGENT in the human-agent window', function () {
    // Facebook: windowHours 24, humanAgentHours 168 — 30h since the last customer
    // message is past the open window but still inside the human-agent window.
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::Facebook]);
    $conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')
        ->create(['handler' => Handler::Human, 'last_customer_message_at' => now()->subHours(30)]);
    $attachment = MessageAttachment::factory()->stored()->create(['message_id' => null, 'uploaded_by' => $this->admin->id]);

    $this->actingAs($this->admin)->postJson("/inbox/conversations/{$conv->id}/messages", ['attachment_ids' => [$attachment->id]])
        ->assertCreated();

    $sent = collect(FakeChannelAdapter::sent())->firstWhere('method', 'sendAttachment');
    expect($sent['options']['tag'] ?? null)->toBe('HUMAN_AGENT');
});

it('fails a caption-less message instead of sending empty text when its attachment row is missing', function () {
    // Simulates the attachment row being gone by the time the queued job runs
    // (e.g. a manual admin deletion): a caption-less row's body is always null
    // by construction (OutboundService::dispatchHuman), so falling through to
    // sendText() would silently deliver a blank message.
    $conv = ($this->platformConv)(Platform::Facebook);
    $message = Message::factory()->create([
        'conversation_id' => $conv->id,
        'direction' => MessageDirection::Out,
        'sender_type' => SenderType::User,
        'user_id' => $this->admin->id,
        'body' => null,
        'status' => MessageStatus::Queued,
    ]);

    app()->call([new SendOutboundMessage($message->id), 'handle']);

    $fresh = Message::find($message->id);
    expect($fresh->status)->toBe(MessageStatus::Failed)->and($fresh->error)->toBe('media_attachment_missing');
    expect(collect(FakeChannelAdapter::sent()))->toBeEmpty();
});

it('prunes unsent uploads older than 24 hours', function () {
    Storage::disk('media')->put('outbound/2026/09/old.png', 'x');
    $old = MessageAttachment::factory()->stored()->create(['message_id' => null, 'uploaded_by' => $this->admin->id, 'path' => 'outbound/2026/09/old.png', 'created_at' => now()->subHours(25)]);
    $fresh = MessageAttachment::factory()->stored()->create(['message_id' => null, 'uploaded_by' => $this->admin->id, 'created_at' => now()->subHours(2)]);

    $this->artisan('crm:prune-media-orphans')->assertSuccessful();

    expect(MessageAttachment::find($old->id))->toBeNull()->and(MessageAttachment::find($fresh->id))->not->toBeNull();
    Storage::disk('media')->assertMissing('outbound/2026/09/old.png');
});

it('prunes an old unlinked outbound copy with no uploader but keeps an inbound pending row (final fix wave I4)', function () {
    Storage::disk('media')->put('outbound/2026/09/bot-copy.png', 'x');
    $botCopy = MessageAttachment::factory()->stored()->create(['message_id' => null, 'uploaded_by' => null, 'path' => 'outbound/2026/09/bot-copy.png', 'created_at' => now()->subHours(25)]);
    $freshCopy = MessageAttachment::factory()->stored()->create(['message_id' => null, 'uploaded_by' => null, 'created_at' => now()->subHours(2)]);
    // Inbound pending rows always carry remote_url/remote_id — never pruned, even with an outbound-looking path.
    $inbound = MessageAttachment::factory()->create(['message_id' => null, 'uploaded_by' => null, 'path' => 'outbound/2026/09/in.png', 'remote_url' => 'https://cdn.example/x.png', 'created_at' => now()->subDays(3)]);
    $inboundById = MessageAttachment::factory()->create(['message_id' => null, 'uploaded_by' => null, 'path' => 'outbound/2026/09/in2.png', 'remote_url' => null, 'remote_id' => 'WA-MEDIA-1', 'created_at' => now()->subDays(3)]);

    $this->artisan('crm:prune-media-orphans')->assertSuccessful();

    expect(MessageAttachment::find($botCopy->id))->toBeNull()
        ->and(MessageAttachment::find($freshCopy->id))->not->toBeNull()
        ->and(MessageAttachment::find($inbound->id))->not->toBeNull()
        ->and(MessageAttachment::find($inboundById->id))->not->toBeNull();
    Storage::disk('media')->assertMissing('outbound/2026/09/bot-copy.png');
});

it('dispatches multi-attachment sends as an ordered chain, caption on the first (final fix wave M2)', function () {
    Bus::fake();
    $conv = ($this->platformConv)(Platform::Facebook);
    $a = MessageAttachment::factory()->stored()->create(['message_id' => null, 'uploaded_by' => $this->admin->id]);
    $b = MessageAttachment::factory()->stored()->create(['message_id' => null, 'uploaded_by' => $this->admin->id]);

    $messages = app(OutboundService::class)->sendHumanWithAttachments($conv, $this->admin, 'الصور', [$a->id, $b->id]);

    expect($messages[0]->body)->toBe('الصور')->and($messages[1]->body)->toBeNull();
    Bus::assertChained([
        fn (SendOutboundMessage $job) => $job->messageId === $messages[0]->id,
        fn (SendOutboundMessage $job) => $job->messageId === $messages[1]->id,
    ]);
});

it('dispatches the rest of a failed chain as independent jobs instead of dropping them (final fix wave M2)', function () {
    $first = Message::factory()->create(['direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'status' => MessageStatus::Queued]);
    $second = Message::factory()->create(['direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'status' => MessageStatus::Queued]);
    $third = Message::factory()->create(['direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'status' => MessageStatus::Queued]);
    $job = (new SendOutboundMessage($first->id))->chain([new SendOutboundMessage($second->id), new SendOutboundMessage($third->id)]);

    Queue::fake();
    $job->failed(new RuntimeException('boom'));

    expect($first->fresh()->status)->toBe(MessageStatus::Failed);
    Queue::assertPushed(SendOutboundMessage::class, 2);
    Queue::assertPushed(SendOutboundMessage::class, fn ($j) => $j->messageId === $second->id && $j->chained === []);
    Queue::assertPushed(SendOutboundMessage::class, fn ($j) => $j->messageId === $third->id && $j->chained === []);
});

it('never re-dispatches the rest of a chain when this link\'s own message was already sent (residual follow-up)', function () {
    $first = Message::factory()->create(['direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'status' => MessageStatus::Sent]);
    $second = Message::factory()->create(['direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'status' => MessageStatus::Queued]);
    $job = (new SendOutboundMessage($first->id))->chain([new SendOutboundMessage($second->id)]);

    Queue::fake();
    $job->failed(new RuntimeException('max attempts on a duplicate delivery'));

    expect($first->fresh()->status)->toBe(MessageStatus::Sent);
    Queue::assertNothingPushed();
});

it('claims a send atomically so two workers can never both send one message (residual follow-up)', function () {
    FakeChannelAdapter::reset();
    $conv = ($this->platformConv)(Platform::Facebook);
    $message = Message::factory()->create(['conversation_id' => $conv->id, 'platform' => Platform::Facebook, 'direction' => MessageDirection::Out,
        'sender_type' => SenderType::User, 'user_id' => $this->admin->id, 'body' => 'متاح', 'status' => MessageStatus::Queued]);

    // Another worker holds the claim: this delivery must not send.
    $other = Cache::lock(SendOutboundMessage::claimKey($message->id), SendOutboundMessage::CLAIM_SECONDS);
    expect($other->get())->toBeTrue();
    app()->call([new SendOutboundMessage($message->id), 'handle']);
    expect(collect(FakeChannelAdapter::sent()))->toBeEmpty()
        ->and($message->fresh()->status)->toBe(MessageStatus::Queued);
    $other->release();

    // The claim holder sends once; a later duplicate delivery finds it Sent and does nothing.
    app()->call([new SendOutboundMessage($message->id), 'handle']);
    app()->call([new SendOutboundMessage($message->id), 'handle']);
    expect(collect(FakeChannelAdapter::sent()))->toHaveCount(1)
        ->and($message->fresh()->status)->toBe(MessageStatus::Sent)
        // The claim is released after the send, never left dangling.
        ->and(Cache::lock(SendOutboundMessage::claimKey($message->id), 1)->get())->toBeTrue();
});

it('never prunes an old attachment already linked to a message, or one without an uploader', function () {
    $message = Message::factory()->create();
    $linked = MessageAttachment::factory()->stored()->create(['message_id' => $message->id, 'uploaded_by' => $this->admin->id, 'created_at' => now()->subDays(3)]);
    // Shaped like an inbound row (no uploader, pending) but with message_id
    // forced null — the uploaded_by guard must still protect it even though
    // whereNull('message_id') alone would already let it through.
    $noUploader = MessageAttachment::factory()->create(['message_id' => null, 'uploaded_by' => null, 'created_at' => now()->subDays(3)]);

    $this->artisan('crm:prune-media-orphans')->assertSuccessful();

    expect(MessageAttachment::find($linked->id))->not->toBeNull()->and(MessageAttachment::find($noUploader->id))->not->toBeNull();
});
