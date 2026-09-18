<?php

use App\Channels\Adapters\MetaGraphClient;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Events\MessageUpdated;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Media\SampleMedia;
use App\Models\ActivityLog;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Final fix wave I1: a provider connection error's message embeds the full request
 * URI (appsecret_proof + tokens in the query string) and must never reach
 * messages.error, the MessageUpdated broadcast or activity_logs.meta.
 */
const LEAKY = 'cURL error 28: Operation timed out for https://graph.facebook.com/v23.0/me/messages?appsecret_proof=secret123&access_token=tok';

beforeEach(function () {
    Event::fake([MessageUpdated::class]);
    Storage::fake('media');
    Http::preventStrayRequests();
    config(['crm.drivers.channels' => 'live', 'crm.meta.app_secret' => 'app-secret']);

    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::Facebook, 'external_id' => 'PSID1']);
    $this->conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')->create(['handler' => Handler::Human, 'last_customer_message_at' => now()]);
    $this->queued = fn (array $attrs = []) => Message::factory()->create(array_merge([
        'conversation_id' => $this->conv->id, 'platform' => Platform::Facebook, 'direction' => MessageDirection::Out,
        'sender_type' => SenderType::Bot, 'status' => MessageStatus::Queued,
    ], $attrs));
});

function assertNoSecretLeak(Message $message): void
{
    $fresh = $message->fresh();
    expect($fresh->status)->toBe(MessageStatus::Failed)
        ->and((string) $fresh->error)->not->toContain('secret123')->not->toContain('tok')->not->toContain('appsecret_proof');

    $meta = json_encode(ActivityLog::where('subject_id', $message->id)->pluck('meta')->all());
    expect($meta)->not->toContain('secret123')->not->toContain('access_token=');

    Event::assertDispatched(MessageUpdated::class, function (MessageUpdated $e) use ($message) {
        $payload = json_encode($e->broadcastWith());

        return $e->message->id === $message->id && ! str_contains($payload, 'secret123') && ! str_contains($payload, 'access_token=');
    });
}

it('never persists a leaky connection error from a text send', function () {
    Http::fake(['graph.facebook.com/*' => Http::failedConnection(LEAKY)]);
    $message = ($this->queued)(['body' => 'أهلا']);

    $job = new SendOutboundMessage($message->id);
    $job->tries = 1; // no worker attached: fail on this attempt instead of release()
    app()->call([$job, 'handle']);

    assertNoSecretLeak($message);
    expect($message->fresh()->error)->toBe('provider_connection_error');
});

it('never persists a leaky connection error from an attachment send', function () {
    Http::fake(['graph.facebook.com/*' => Http::failedConnection(LEAKY)]);
    $message = ($this->queued)(['body' => null]);
    $path = 'outbound/2026/09/'.Str::uuid().'.png';
    Storage::disk('media')->put($path, SampleMedia::bytes('image'));
    MessageAttachment::factory()->stored()->create(['message_id' => $message->id, 'path' => $path]);

    $job = new SendOutboundMessage($message->id);
    $job->tries = 1;
    app()->call([$job, 'handle']);

    assertNoSecretLeak($message);
});

it('maps a thrown exception to a code in failed()', function () {
    $message = ($this->queued)(['body' => 'أهلا']);

    (new SendOutboundMessage($message->id))->failed(new ConnectionException(LEAKY));

    assertNoSecretLeak($message);
    expect($message->fresh()->error)->toBe('provider_connection_error');

    $other = ($this->queued)(['body' => 'تاني']);
    (new SendOutboundMessage($other->id))->failed(new RuntimeException(LEAKY));
    expect($other->fresh()->error)->toBe('send_exception:RuntimeException');
});

it('strips the query string from connection exceptions raised by MetaGraphClient', function () {
    Http::fake(['graph.facebook.com/*' => Http::failedConnection(LEAKY)]);
    $acc = ChannelAccount::first();

    foreach ([
        fn () => app(MetaGraphClient::class)->post($acc, 'me/messages', ['a' => 1]),
        fn () => app(MetaGraphClient::class)->postFast($acc, 'me/messages', ['a' => 1]),
        fn () => app(MetaGraphClient::class)->get($acc, 'me'),
    ] as $call) {
        try {
            $call();
            $this->fail('expected a ConnectionException');
        } catch (ConnectionException $e) {
            expect($e->getMessage())->not->toContain('secret123')->not->toContain('access_token')->not->toContain('?')
                ->and($e->getPrevious())->toBeNull();
        }
    }
});
