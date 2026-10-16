<?php

// Audit A3: an event for a page / IG account / WhatsApp number we have no CONNECTED
// account for is dropped — it used to land on the platform's first account.

use App\Channels\Data\InboundMessageData;
use App\Channels\Jobs\ProcessWebhookEvent;
use App\Channels\UnknownChannelAccount;
use App\Enums\Platform;
use App\Inbox\InboxIngestor;
use App\Models\ChannelAccount;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Post;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->page = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'page-ours', 'status' => 'connected']);
});

function channelEvent(array ...$events): WebhookEvent
{
    return WebhookEvent::factory()->create([
        'provider' => 'facebook',
        'payload' => ['fake' => true, 'events' => $events],
        'status' => 'received',
        'attempts' => 0,
    ]);
}

function channelMessage(string $channelId, string $id = 'm1'): array
{
    return ['type' => 'message', 'channel_id' => $channelId, 'customer_id' => 'c-'.$id, 'name' => 'Mona', 'text' => 'بكام', 'id' => $id, 'at' => '2026-10-16T10:00:00Z'];
}

function channelComment(string $channelId): array
{
    return ['type' => 'comment', 'channel_id' => $channelId, 'post_id' => 'p1', 'post_caption' => 'x', 'is_ad' => false, 'comment_id' => 'cm1', 'customer_id' => 'c1', 'name' => 'Mona', 'text' => 'بكام', 'at' => '2026-10-16T10:00:00Z'];
}

it('drops a message for a page we have no account for', function () {
    Log::spy();
    $event = channelEvent(channelMessage('page-someone-else'));

    ProcessWebhookEvent::dispatchSync($event->id);

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0)
        ->and($event->fresh()->status)->toBe('ignored')
        ->and($event->fresh()->error)->toContain('page-someone-else');
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $msg === 'webhook.unknown_channel_account' && $ctx['channel_external_id'] === 'page-someone-else');
});

it('drops a message for a disconnected account', function () {
    $this->page->update(['status' => 'disconnected']);
    $event = channelEvent(channelMessage('page-ours'));

    ProcessWebhookEvent::dispatchSync($event->id);

    expect(Message::count())->toBe(0)
        ->and($event->fresh()->status)->toBe('ignored');
});

it('drops a comment for a page we have no account for', function () {
    $event = channelEvent(channelComment('page-someone-else'));

    ProcessWebhookEvent::dispatchSync($event->id);

    expect(Comment::count())->toBe(0)
        ->and(Post::count())->toBe(0)
        ->and($event->fresh()->status)->toBe('ignored');
});

it('ingests on the matching connected account', function () {
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'page-other', 'status' => 'connected']);
    $event = channelEvent(channelMessage('page-ours'), channelComment('page-ours'));

    ProcessWebhookEvent::dispatchSync($event->id);

    expect(Conversation::pluck('channel_account_id')->unique()->all())->toBe([$this->page->id])
        ->and(Message::where('direction', 'in')->count())->toBe(1)
        ->and(Post::sole()->channel_account_id)->toBe($this->page->id)
        ->and($event->fresh()->status)->toBe('processed');
});

it('still processes the known half of a mixed event', function () {
    $event = channelEvent(channelMessage('page-ours', 'm1'), channelMessage('page-someone-else', 'm2'));

    ProcessWebhookEvent::dispatchSync($event->id);

    expect(Message::where('direction', 'in')->pluck('external_id')->all())->toBe(['m1'])
        ->and($event->fresh()->status)->toBe('processed')
        ->and($event->fresh()->error)->toContain('page-someone-else');
});

it('refuses a direct ingest for an unknown account', function () {
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        platform: Platform::Facebook,
        channelExternalId: 'page-someone-else',
        customerExternalId: 'c9',
        customerName: 'Mona',
        externalMessageId: 'm9',
        body: 'hi',
        occurredAt: CarbonImmutable::now(),
    ));
})->throws(UnknownChannelAccount::class);
