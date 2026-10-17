<?php

use App\Channels\Data\InboundMessageData;
use App\Channels\Data\OutboundEchoData;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Inbox\EchoIngestor;
use App\Inbox\InboxIngestor;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

// Replies sent outside the CRM (2026-10-17): Meta mirrors every page send back as an echo.

beforeEach(function () {
    Event::fake();
    BotSetting::current()->update(['enabled' => false]);
    config(['crm.meta.app_id' => '4384979978437728']);
    $this->account = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'cust-1', 'Nour', 'in-1', 'بكام الفستان', CarbonImmutable::now()->subMinutes(3)));
    $this->conversation = Conversation::firstOrFail();
});

function echoOf(string $mid, string $text = 'تحت أمرك', ?string $appId = '263902037430900', array $attachments = []): OutboundEchoData
{
    return new OutboundEchoData(Platform::Facebook, 'PAGE1', 'cust-1', $mid, $text, CarbonImmutable::now(), $attachments, $appId);
}

it('records a reply sent from Business Suite as a staff message sent outside the CRM', function () {
    $message = app(EchoIngestor::class)->ingest(echoOf('m_bs_1'));

    expect($message)->not->toBeNull()
        ->and($message->conversation_id)->toBe($this->conversation->id)
        ->and($message->direction)->toBe(MessageDirection::Out)
        ->and($message->sender_type)->toBe(SenderType::User)
        ->and($message->user_id)->toBeNull()
        ->and($message->sent_outside_crm)->toBeTrue()
        ->and($message->status)->toBe(MessageStatus::Sent)
        ->and($message->external_id)->toBe('m_bs_1')
        ->and($message->body)->toBe('تحت أمرك');
});

it('is idempotent on the echo mid', function () {
    app(EchoIngestor::class)->ingest(echoOf('m_bs_1'));

    expect(app(EchoIngestor::class)->ingest(echoOf('m_bs_1')))->toBeNull()
        ->and(Message::where('external_id', 'm_bs_1')->count())->toBe(1);
});

it('skips echoes of messages the CRM sent: by our app id, and by a mid we already stored', function () {
    expect(app(EchoIngestor::class)->ingest(echoOf('m_ours_1', 'أهلا', '4384979978437728')))->toBeNull();

    $sent = Message::factory()->create([
        'conversation_id' => $this->conversation->id, 'platform' => Platform::Facebook,
        'direction' => MessageDirection::Out, 'sender_type' => SenderType::Bot, 'external_id' => 'm_ours_2', 'status' => MessageStatus::Sent,
    ]);

    expect(app(EchoIngestor::class)->ingest(echoOf('m_ours_2', 'أهلا', null)))->toBeNull()
        ->and(Message::where('sent_outside_crm', true)->count())->toBe(0)
        ->and($sent->fresh()->sent_outside_crm)->toBeFalse();
});

it('skips an echo that races ahead of our own send (same text still queued in the thread)', function () {
    Message::factory()->create([
        'conversation_id' => $this->conversation->id, 'platform' => Platform::Facebook,
        'direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'body' => 'هبعتلك الصور',
        'external_id' => null, 'status' => MessageStatus::Queued,
    ]);

    expect(app(EchoIngestor::class)->ingest(echoOf('m_race', 'هبعتلك الصور', null)))->toBeNull();
});

it('drops the echo row when our own send lands later with the same mid', function () {
    $echo = app(EchoIngestor::class)->ingest(echoOf('m_late', 'رد', null));
    expect($echo)->not->toBeNull();

    $ours = Message::factory()->create([
        'conversation_id' => $this->conversation->id, 'platform' => Platform::Facebook,
        'direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'body' => 'رد مختلف',
        'external_id' => null, 'status' => MessageStatus::Queued,
    ]);

    SendOutboundMessage::recordSent($ours, 'm_late');

    expect(Message::find($echo->id))->toBeNull()
        ->and($ours->fresh()->external_id)->toBe('m_late')
        ->and($ours->fresh()->status)->toBe(MessageStatus::Sent);
});

it('never reopens, marks unread, runs the bot or hands over', function () {
    BotSetting::current()->update(['enabled' => true]);
    $this->conversation->forceFill(['status' => ConversationStatus::Resolved, 'unread_count' => 0, 'handler' => Handler::Bot])->save();
    $before = Message::count();

    app(EchoIngestor::class)->ingest(echoOf('m_bs_2'));

    $c = $this->conversation->fresh();
    expect($c->status)->toBe(ConversationStatus::Resolved)
        ->and($c->unread_count)->toBe(0)
        ->and($c->handler)->toBe(Handler::Bot)
        ->and(Message::count())->toBe($before + 1)
        ->and(Message::where('direction', MessageDirection::In)->count())->toBe(1);
});

it('does not move the customer window clock', function () {
    $before = $this->conversation->last_customer_message_at;

    app(EchoIngestor::class)->ingest(echoOf('m_bs_3'));

    expect($this->conversation->fresh()->last_customer_message_at?->toIso8601String())->toBe($before?->toIso8601String());
});

it('skips an echo for a customer or page we do not know', function () {
    expect(app(EchoIngestor::class)->ingest(new OutboundEchoData(Platform::Facebook, 'PAGE1', 'stranger', 'm_x', 'hi', CarbonImmutable::now())))->toBeNull()
        ->and(fn () => app(EchoIngestor::class)->ingest(new OutboundEchoData(Platform::Facebook, 'OTHER', 'cust-1', 'm_y', 'hi', CarbonImmutable::now())))
        ->toThrow(\App\Channels\UnknownChannelAccount::class);
});

it('records an image sent from the phone app as a pending attachment', function () {
    $message = app(EchoIngestor::class)->ingest(echoOf('m_img', '', null, [['type' => 'image', 'url' => 'https://cdn.example/x.jpg']]));

    expect($message->mediaAttachments()->count())->toBe(1);
});

it('exposes the outside-CRM flag to the thread', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    app(EchoIngestor::class)->ingest(echoOf('m_bs_4'));

    $this->actingAs($user)->getJson("/inbox/conversations/{$this->conversation->id}/messages")
        ->assertOk()
        ->assertJsonFragment(['sent_outside_crm' => true]);
});

it('routes echoes from the webhook job to the echo ingestor', function () {
    config(['crm.drivers.channels' => 'live']);
    $this->account->update(['driver' => 'live']);
    $event = \App\Models\WebhookEvent::create([
        'provider' => 'facebook', 'dedupe_key' => 'evt-echo-1', 'status' => 'pending',
        'payload' => ['object' => 'page', 'entry' => [['id' => 'PAGE1', 'time' => 1757671320000, 'messaging' => [[
            'sender' => ['id' => 'PAGE1'], 'recipient' => ['id' => 'cust-1'], 'timestamp' => 1757671320000,
            'message' => ['is_echo' => true, 'mid' => 'm_job_1', 'text' => 'من البيزنس سويت', 'app_id' => 263902037430900],
        ]]]]],
        'received_at' => now(),
    ]);

    (new \App\Channels\Jobs\ProcessWebhookEvent($event->id))->handle(app(\App\Channels\ChannelRegistry::class));

    expect(Message::where('external_id', 'm_job_1')->value('sent_outside_crm'))->toBeTruthy()
        ->and($event->fresh()->status)->toBe('processed');
});

it('subscribes pages to message echoes, and the health check asks for a re-subscribe without them', function () {
    expect(\App\Channels\MetaPageSubscriber::FIELDS)->toContain('message_echoes');
});

it('skips the caption echo of our picture even after the picture was read', function () {
    Message::factory()->create([
        'conversation_id' => $this->conversation->id, 'platform' => Platform::Facebook,
        'direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'body' => 'ده الموديل',
        'external_id' => 'm_media', 'status' => MessageStatus::Read,
    ]);

    expect(app(EchoIngestor::class)->ingest(echoOf('m_caption', 'ده الموديل', null)))->toBeNull()
        ->and(Message::where('sent_outside_crm', true)->count())->toBe(0);
});

it('marks a send we gave up on as sent when its echo proves Meta delivered it', function () {
    $failed = Message::factory()->create([
        'conversation_id' => $this->conversation->id, 'platform' => Platform::Facebook,
        'direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'body' => 'متاح مقاس L',
        'external_id' => null, 'status' => MessageStatus::Failed, 'error' => 'timeout',
    ]);

    expect(app(EchoIngestor::class)->ingest(echoOf('m_late_ok', 'متاح مقاس L', null)))->toBeNull();

    $fresh = $failed->fresh();
    expect($fresh->status)->toBe(MessageStatus::Sent)
        ->and($fresh->external_id)->toBe('m_late_ok')
        ->and(Message::where('sent_outside_crm', true)->count())->toBe(0);
});

it('skips an echo with nothing to show (another tool\'s template without text)', function () {
    expect(app(EchoIngestor::class)->ingest(echoOf('m_tpl', '', '111', [['type' => 'template', 'url' => null]])))->toBeNull();
});

it('reads the text of another tool\'s template echo', function () {
    $events = app(\App\Channels\Adapters\MessengerAdapter::class)->normalize(['object' => 'page', 'entry' => [['id' => 'PAGE1', 'time' => 1757671320000, 'messaging' => [[
        'sender' => ['id' => 'PAGE1'], 'recipient' => ['id' => 'cust-1'], 'timestamp' => 1757671320000,
        'message' => ['is_echo' => true, 'mid' => 'm_tpl2', 'app_id' => 999, 'attachments' => [['type' => 'template', 'payload' => ['template_type' => 'button', 'text' => 'اختاري المقاس']]]],
    ]]]]]);

    expect($events[0])->toBeInstanceOf(OutboundEchoData::class)
        ->and($events[0]->body)->toBe('اختاري المقاس')
        ->and($events[0]->appId)->toBe('999');
});
