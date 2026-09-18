<?php

use App\Channels\Data\InboundMessageData;
use App\Enums\{AttachmentStatus, Platform, UserRole};
use App\Inbox\InboxIngestor;
use App\Media\{InboundMediaFetcher, SampleMedia};
use App\Media\Jobs\DownloadInboundMedia;
use App\Models\{ChannelAccount, MessageAttachment, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Event, Http, Queue, Storage};
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('media');
    Event::fake();
    Http::preventStrayRequests();
});

function inboundWith(Platform $p, array $attachments, string $body = ''): InboundMessageData {
    ChannelAccount::firstOrCreate(['platform' => $p->value], ['name' => 'x', 'external_id' => 'acc-'.$p->value, 'driver' => 'fake', 'status' => 'connected']);
    return new InboundMessageData($p, 'acc-'.$p->value, 'cust-1', 'Mona', 'mid-'.Str::uuid(), $body, CarbonImmutable::now(), $attachments);
}

it('records pending rows and queues downloads on the media queue without downloading inline', function () {
    Queue::fake();
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Facebook, [
        ['type' => 'image', 'url' => 'https://cdn.example/x.jpg'], ['type' => 'story_mention', 'url' => 'https://x'],
    ]));

    expect($m->mediaAttachments)->toHaveCount(1)
        ->and($m->mediaAttachments[0]->status)->toBe(AttachmentStatus::Pending)
        ->and($m->mediaAttachments[0]->remote_url)->toBe('https://cdn.example/x.jpg');
    Queue::assertPushedOn('media', DownloadInboundMedia::class);
});

it('stores a simulator fixture and broadcasts the update', function () {
    Queue::fake();
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Instagram, [['type' => 'audio', 'fixture' => 'voice']]));
    $id = $m->mediaAttachments[0]->id;

    app()->call([new DownloadInboundMedia($id), 'handle']);

    $a = MessageAttachment::find($id);
    expect($a->status)->toBe(AttachmentStatus::Stored)->and($a->mime)->toBe('audio/ogg')->and($a->path)->toStartWith('inbound/');
    Event::assertDispatched(App\Events\MessageUpdated::class);
});

it('resolves whatsapp media ids through the graph api with the account token', function () {
    Queue::fake();
    config(['crm.drivers.channels' => 'live']);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'external_id' => 'acc-whatsapp', 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    Http::fake([
        'graph.facebook.com/*/MEDIA9*' => Http::response(['url' => 'https://lookaside.fbsbx.com/m9', 'mime_type' => 'image/png']),
        'lookaside.fbsbx.com/*' => Http::response(SampleMedia::bytes('image'), 200, ['Content-Type' => 'image/png']),
    ]);
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::WhatsApp, [['type' => 'image', 'id' => 'MEDIA9', 'mime_type' => 'image/png']]));

    app()->call([new DownloadInboundMedia($m->mediaAttachments[0]->id), 'handle']);

    expect($m->mediaAttachments[0]->fresh()->status)->toBe(AttachmentStatus::Stored);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'lookaside.fbsbx.com') && $r->hasHeader('Authorization', 'Bearer tok'));
});

it('rejects a download host outside the meta cdn allow-list without ever calling it', function () {
    Queue::fake();
    config(['crm.drivers.channels' => 'live']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'acc-facebook', 'driver' => 'live']);
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Facebook, [['type' => 'image', 'url' => 'https://evil.example/x.jpg']]));
    $job = new DownloadInboundMedia($m->mediaAttachments[0]->id);

    // Http::preventStrayRequests() (beforeEach) would itself throw if the host
    // check didn't reject the url before any request was attempted.
    expect(fn () => app()->call([$job, 'handle']))->toThrow(App\Media\MediaFetchFailed::class, 'host_not_allowed');
});

it('rejects an insecure http url even on an otherwise-allowed host', function () {
    Queue::fake();
    config(['crm.drivers.channels' => 'live']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'acc-facebook', 'driver' => 'live']);
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Facebook, [['type' => 'image', 'url' => 'http://cdn.fbcdn.net/x.jpg']]));
    $job = new DownloadInboundMedia($m->mediaAttachments[0]->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(App\Media\MediaFetchFailed::class, 'insecure_scheme');
});

it('rejects a download whose declared Content-Length exceeds the type max', function () {
    Queue::fake();
    config(['crm.drivers.channels' => 'live', 'crm.media.types.image.max_bytes' => 10]);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'acc-facebook', 'driver' => 'live']);
    Http::fake(['cdn.fbcdn.net/*' => Http::response('x', 200, ['Content-Length' => '999'])]);
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Facebook, [['type' => 'image', 'url' => 'https://cdn.fbcdn.net/x.jpg']]));
    $job = new DownloadInboundMedia($m->mediaAttachments[0]->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(App\Media\MediaFetchFailed::class, 'download_too_large');
    Http::assertSentCount(1); // rejected on the declared header, no further reads
});

it('aborts mid-stream once the body exceeds the type max even with no Content-Length header', function () {
    Queue::fake();
    config(['crm.drivers.channels' => 'live', 'crm.media.types.image.max_bytes' => 10]);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'acc-facebook', 'driver' => 'live']);
    Http::fake(['cdn.fbcdn.net/*' => Http::response(str_repeat('a', 1000), 200)]);
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Facebook, [['type' => 'image', 'url' => 'https://cdn.fbcdn.net/x.jpg']]));
    $job = new DownloadInboundMedia($m->mediaAttachments[0]->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(App\Media\MediaFetchFailed::class, 'download_too_large');
});

it('rejects a malformed url that parse_url cannot parse, without a php notice', function () {
    // Controller ruling: assertUrlAllowed() must guard parse_url() returning false
    // (e.g. a scheme-relative-looking "http:///host" url) instead of emitting an
    // "Undefined array key" notice while reading ['scheme']/['host'] off `false`.
    Queue::fake();
    config(['crm.drivers.channels' => 'live']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'acc-facebook', 'driver' => 'live']);
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Facebook, [['type' => 'image', 'url' => 'http:///example.com']]));
    $job = new DownloadInboundMedia($m->mediaAttachments[0]->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(App\Media\MediaFetchFailed::class, 'host_not_allowed');
});

it('never leaks the raw exception text into the attachment error column', function () {
    Queue::fake();
    config(['crm.drivers.channels' => 'live']);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'acc-facebook', 'driver' => 'live']);
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Facebook, [['type' => 'image', 'url' => 'https://evil.example/x.jpg']]));
    $job = new DownloadInboundMedia($m->mediaAttachments[0]->id);

    try {
        app()->call([$job, 'handle']);
    } catch (App\Media\MediaFetchFailed $e) {
        $job->failed($e);
    }

    expect($m->mediaAttachments[0]->fresh()->error)->toBe('host_not_allowed');
});

it('never calls the network on the fake driver for non-fixture urls and marks failures', function () {
    Queue::fake();
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Facebook, [['type' => 'image', 'url' => 'https://cdn.example/x.jpg']]));
    $job = new DownloadInboundMedia($m->mediaAttachments[0]->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(App\Media\MediaFetchFailed::class);
    $job->failed(new App\Media\MediaFetchFailed('fake_driver_no_network'));

    expect($m->mediaAttachments[0]->fresh()->status)->toBe(AttachmentStatus::Failed);
});

it('retries a failed download from the bubble', function () {
    Queue::fake();
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Facebook, [['type' => 'image', 'fixture' => 'image']]));
    $a = $m->mediaAttachments[0];
    $a->forceFill(['status' => AttachmentStatus::Failed, 'error' => 'x'])->save();
    Queue::fake();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->postJson(route('media.retry', $a))->assertOk()->assertJsonPath('data.status', 'pending');
    Queue::assertPushedOn('media', DownloadInboundMedia::class);
});

it('forbids a moderator without access to the attachment platform from retrying', function () {
    Queue::fake();
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Facebook, [['type' => 'image', 'fixture' => 'image']]));
    $a = $m->mediaAttachments[0];
    $a->forceFill(['status' => AttachmentStatus::Failed, 'error' => 'x'])->save();
    Queue::fake();

    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::WhatsApp]); // not Facebook

    $this->actingAs($mod)->postJson(route('media.retry', $a))->assertForbidden();
});

it('sanitises a slash-bearing filename when recording an inbound attachment', function () {
    Queue::fake();
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Facebook, [
        ['type' => 'file', 'url' => 'https://cdn.example/x', 'filename' => 'فاتورة 1/2.pdf'],
    ]));

    expect($m->mediaAttachments[0]->original_name)->toBe('فاتورة 1_2.pdf');
});

it('returns attachments from the relation in the conversation detail', function () {
    Queue::fake();
    $m = app(InboxIngestor::class)->ingestMessage(inboundWith(Platform::Facebook, [['type' => 'image', 'fixture' => 'image']]));
    app()->call([new DownloadInboundMedia($m->mediaAttachments[0]->id), 'handle']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->getJson("/inbox/conversations/{$m->conversation_id}")
        ->assertJsonPath('messages.0.attachments.0.type', 'image')
        ->assertJsonPath('messages.0.attachments.0.status', 'stored')
        ->assertJsonPath('messages.0.attachments.0.url', route('media.show', $m->mediaAttachments[0], false));
});

it('backfills legacy json attachments once', function () {
    $msg = App\Models\Message::factory()->create(['attachments' => [['type' => 'image', 'url' => 'https://old'], ['type' => 'fallback', 'url' => 'x']]]);
    expect(app(App\Media\LegacyAttachmentBackfill::class)->run())->toBe(1)
        ->and(app(App\Media\LegacyAttachmentBackfill::class)->run())->toBe(0)
        ->and($msg->mediaAttachments()->first()->remote_url)->toBe('https://old');
});
