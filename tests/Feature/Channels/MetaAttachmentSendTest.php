<?php

use App\Channels\Adapters\MessengerAdapter;
use App\Channels\Adapters\WhatsAppAdapter;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Media\MediaPolicy;
use App\Media\SampleMedia;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('media');
    Http::preventStrayRequests();
});

function fileAttachment(string $kind, array $attrs = []): MessageAttachment
{
    $path = 'outbound/2026/09/'.Str::uuid().'.bin';
    Storage::disk('media')->put($path, SampleMedia::bytes($kind));

    return MessageAttachment::factory()->stored()->create(array_merge(['path' => $path, 'mime' => SampleMedia::mime($kind),
        'type' => $kind === 'voice' ? 'audio' : $kind, 'original_name' => SampleMedia::filename($kind)], $attrs));
}

it('sends messenger media by temporary signed url then the caption as text', function () {
    Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['message_id' => 'm.media'])->push(['message_id' => 'm.caption'])]);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PSID1']);

    $result = app(MessengerAdapter::class)->sendAttachment($acc, $to, fileAttachment('image'), 'متاح بالأحمر');

    expect($result->success)->toBeTrue()->and($result->externalId)->toBe('m.media');
    $recorded = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
    expect($recorded[0]['message']['attachment']['type'])->toBe('image')
        ->and($recorded[0]['message']['attachment']['payload']['url'])->toContain('/media/public/')->toContain('signature=')
        ->and($recorded[1]['message']['text'])->toBe('متاح بالأحمر');
});

it('uploads to whatsapp cloud media then sends a document with filename and caption', function () {
    Http::fake([
        'graph.facebook.com/*/PN1/media*' => Http::response(['id' => 'MEDIA-ID']),
        'graph.facebook.com/*/PN1/messages*' => Http::response(['messages' => [['id' => 'wamid.X']]]),
    ]);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'external_id' => 'PN1', 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => Platform::WhatsApp, 'external_id' => '201001234567']);

    $result = app(WhatsAppAdapter::class)->sendAttachment($acc, $to, fileAttachment('file'), 'الفاتورة');

    expect($result->success)->toBeTrue()->and($result->externalId)->toBe('wamid.X');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/PN1/media') && $r->isMultipart());
    Http::assertSent(fn ($r) => str_contains($r->url(), '/PN1/messages') && $r['type'] === 'document'
        && $r['document'] === ['id' => 'MEDIA-ID', 'caption' => 'الفاتورة', 'filename' => 'catalog.pdf']);
});

it('fails a webm voice note on whatsapp when ffmpeg is not configured', function () {
    config(['crm.media.ffmpeg_path' => null]);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'external_id' => 'PN1', 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => Platform::WhatsApp]);

    $result = app(WhatsAppAdapter::class)->sendAttachment($acc, $to, fileAttachment('voice', ['mime' => 'audio/webm']));

    expect($result->success)->toBeFalse()->and($result->error)->toBe(MediaPolicy::WHATSAPP_VOICE_UNSUPPORTED);
    Http::assertNothingSent();
});

it('transcodes a webm voice note to ogg opus when ffmpeg is configured', function () {
    config(['crm.media.ffmpeg_path' => 'C:/ffmpeg/bin/ffmpeg.exe']);
    Process::fake(function (PendingProcess $process) {
        file_put_contents(end($process->command), SampleMedia::bytes('voice'));

        return Process::result();
    });
    Http::fake([
        'graph.facebook.com/*/PN1/media*' => Http::response(['id' => 'VOICE-ID']),
        'graph.facebook.com/*/PN1/messages*' => Http::response(['messages' => [['id' => 'wamid.V']]]),
    ]);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'external_id' => 'PN1', 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => Platform::WhatsApp]);

    expect(app(WhatsAppAdapter::class)->sendAttachment($acc, $to, fileAttachment('voice', ['mime' => 'audio/webm']))->success)->toBeTrue();
    Process::assertRan(fn (PendingProcess $p) => in_array('libopus', $p->command, true));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/PN1/messages') && $r['type'] === 'audio' && $r['audio'] === ['id' => 'VOICE-ID']);
});

it('transcodes a video/webm-sniffed voice recording (browser mislabeling) the same as audio/webm', function () {
    // Some browser/finfo combinations report an audio-only webm recording as
    // "video/webm" even though it was already typed Audio at upload time — the
    // transcode decision must not depend on which of the two labels stuck.
    config(['crm.media.ffmpeg_path' => 'C:/ffmpeg/bin/ffmpeg.exe']);
    Process::fake(function (PendingProcess $process) {
        file_put_contents(end($process->command), SampleMedia::bytes('voice'));

        return Process::result();
    });
    Http::fake([
        'graph.facebook.com/*/PN1/media*' => Http::response(['id' => 'VOICE-ID']),
        'graph.facebook.com/*/PN1/messages*' => Http::response(['messages' => [['id' => 'wamid.V']]]),
    ]);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'external_id' => 'PN1', 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => Platform::WhatsApp]);

    expect(app(WhatsAppAdapter::class)->sendAttachment($acc, $to, fileAttachment('voice', ['mime' => 'video/webm']))->success)->toBeTrue();
    Process::assertRan(fn (PendingProcess $p) => in_array('libopus', $p->command, true));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/PN1/messages') && $r['type'] === 'audio' && $r['audio'] === ['id' => 'VOICE-ID']);
});

it('sends the media exactly once and stays success when the messenger caption follow-up throws', function () {
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['message_id' => 'm.media'])
        ->pushFailedConnection('timed out')
        ->pushFailedConnection('timed out')
        ->pushFailedConnection('timed out')]);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PSID1']);

    $result = app(MessengerAdapter::class)->sendAttachment($acc, $to, fileAttachment('image'), 'متاح بالأحمر');

    expect($result->success)->toBeTrue()->and($result->externalId)->toBe('m.media');
    $mediaPosts = Http::recorded()->filter(fn ($pair) => isset($pair[0]->data()['message']['attachment']));
    expect($mediaPosts)->toHaveCount(1);
});

it('sends the media exactly once and stays success when the messenger caption follow-up returns an error', function () {
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['message_id' => 'm.media'])
        ->whenEmpty(Http::response(['error' => ['message' => 'boom']], 500))]);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PSID1']);

    $result = app(MessengerAdapter::class)->sendAttachment($acc, $to, fileAttachment('image'), 'متاح بالأحمر');

    expect($result->success)->toBeTrue()->and($result->externalId)->toBe('m.media');
    $mediaPosts = Http::recorded()->filter(fn ($pair) => isset($pair[0]->data()['message']['attachment']));
    expect($mediaPosts)->toHaveCount(1);
});

it('sends the media exactly once and stays success when the whatsapp caption follow-up throws', function () {
    // Audio is not captionable on WhatsApp: the caption rides as a separate
    // sendText() call to the same /messages endpoint after the media send.
    Http::fake([
        'graph.facebook.com/*/PN1/media*' => Http::response(['id' => 'VOICE-ID']),
        'graph.facebook.com/*/PN1/messages*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.V']]])
            ->pushFailedConnection('timed out')
            ->pushFailedConnection('timed out')
            ->pushFailedConnection('timed out'),
    ]);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'external_id' => 'PN1', 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $to = CustomerIdentity::factory()->create(['platform' => Platform::WhatsApp]);

    $result = app(WhatsAppAdapter::class)->sendAttachment($acc, $to, fileAttachment('voice'), 'ملاحظة صوتية');

    expect($result->success)->toBeTrue()->and($result->externalId)->toBe('wamid.V');
    $mediaSends = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), '/PN1/messages') && ($pair[0]->data()['type'] ?? null) === 'audio');
    expect($mediaSends)->toHaveCount(1);
});

it('keeps the message Sent through the full outbound job even when the caption follow-up throws', function () {
    config(['crm.drivers.channels' => 'live']);
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['message_id' => 'm.media'])
        ->pushFailedConnection('timed out')
        ->pushFailedConnection('timed out')
        ->pushFailedConnection('timed out')]);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::Facebook, 'external_id' => 'PSID1']);
    $conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')
        ->create(['handler' => Handler::Human, 'platform' => Platform::Facebook]);
    $message = Message::factory()->create([
        'conversation_id' => $conv->id, 'platform' => Platform::Facebook, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::User,
        'body' => 'متاح بالأحمر', 'status' => MessageStatus::Queued,
    ]);
    fileAttachment('image', ['message_id' => $message->id]);

    app()->call([new SendOutboundMessage($message->id), 'handle']);

    $fresh = Message::find($message->id);
    expect($fresh->status)->toBe(MessageStatus::Sent)->and($fresh->external_id)->toBe('m.media');
    $mediaPosts = Http::recorded()->filter(fn ($pair) => isset($pair[0]->data()['message']['attachment']));
    expect($mediaPosts)->toHaveCount(1);
});
