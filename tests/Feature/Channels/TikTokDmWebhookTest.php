<?php

// TikTok direct messages (x6, 2026-10-24): the webhook door. Payloads are shaped exactly as the Business
// Messaging webhook schema documents them (content is a JSON *string*), signed with
// `Tiktok-Signature: t=<seconds>,s=<hmac>` over `t . body` with the app secret.

use App\Channels\Adapters\TikTok\FakeTikTokApi;
use App\Channels\Adapters\TikTokAdapter;
use App\Channels\Data\InboundMessageData;
use App\Channels\Data\OutboundEchoData;
use App\Channels\Jobs\ProcessWebhookEvent;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\TikTokConversation;
use App\Models\WebhookEvent;
use App\Models\WebhookRejection;
use Illuminate\Support\Facades\Queue;

const TT_SECRET = 'tiktok-app-secret-xyz';

beforeEach(function () {
    FakeTikTokApi::reset();
    config([
        'crm.drivers.channels' => 'live',
        'services.tiktok.app_id' => '7591714577012752400',
        'services.tiktok.app_secret' => TT_SECRET,
        'services.tiktok.signature_tolerance_seconds' => 300,
    ]);
    $this->account = ChannelAccount::factory()->create([
        'platform' => Platform::TikTok, 'driver' => 'live', 'external_id' => 'open-id-levoile', 'name' => 'Le Voile',
        'credentials' => ['access_token' => 'tt-access', 'refresh_token' => 'tt-refresh', 'access_token_expires_at' => now()->addDay()->toIso8601String()],
        'profile' => ['kind' => 'messaging', 'username' => 'levoilestores'],
    ]);
});

/** @param  array<string, mixed>  $content */
function ttEvent(string $event, array $content, string $openId = 'open-id-levoile'): array
{
    return ['client_key' => '7591714577012752400', 'event' => $event, 'create_time' => 1761300000, 'user_openid' => $openId, 'content' => json_encode($content, JSON_UNESCAPED_UNICODE)];
}

/** @param  array<string, mixed>  $extra */
function ttReceive(array $extra = [], string $type = 'text'): array
{
    return ttEvent('im_receive_msg', array_merge([
        'from' => 'mona.adel', 'to' => 'levoilestores', 'unique_identifier' => 'uid-mona',
        'from_user' => ['id' => 'uid-mona', 'role' => 'personal_account'], 'to_user' => ['id' => 'open-id-levoile', 'role' => 'business_account'],
        'conversation_id' => 'conv-abc+/==', 'message_id' => 'msg-1', 'timestamp' => 1761300000123, 'type' => $type,
        'scene_type' => 0, 'is_follower' => true, 'message_tag' => ['source' => 'APP'],
    ], $extra));
}

function ttSign(string $body, ?int $t = null, string $secret = TT_SECRET): string
{
    $t ??= time();

    return 't='.$t.',s='.hash_hmac('sha256', $t.'.'.$body, $secret);
}

function ttPost(array $payload, ?string $signature = null)
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($signature !== null) {
        $headers['HTTP_TIKTOK_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/webhooks/tiktok', [], [], [], $headers, $body);
}

describe('signature', function () {
    it('accepts a correctly signed delivery, stores it once and queues the job', function () {
        Queue::fake();
        $payload = ttReceive(['text' => ['body' => 'بكام الفستان؟']]);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        ttPost($payload, ttSign($body))->assertOk();
        ttPost($payload, ttSign($body))->assertOk();

        expect(WebhookEvent::where('provider', 'tiktok')->count())->toBe(1)
            ->and(WebhookEvent::first()->signature_valid)->toBeTrue();
        Queue::assertPushedOn('webhooks', ProcessWebhookEvent::class);
        Queue::assertPushed(ProcessWebhookEvent::class, 1);
        expect($this->account->fresh()->last_webhook_at)->not->toBeNull();
    });

    it('refuses a bad, missing or stale signature and records the reason for Settings', function () {
        Queue::fake();
        $payload = ttReceive(['text' => ['body' => 'hi']]);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        ttPost($payload, ttSign($body, secret: 'another-secret'))->assertForbidden();
        ttPost($payload)->assertForbidden();
        ttPost($payload, ttSign($body, t: time() - 3600))->assertForbidden();
        ttPost($payload, 'garbage')->assertForbidden();

        expect(WebhookEvent::count())->toBe(0);
        Queue::assertNothingPushed();
        // One row per provider per hour: the count adds up, the last reason is the last refusal's.
        expect((int) WebhookRejection::where('provider', 'tiktok')->sum('rejected'))->toBe(4)
            ->and(WebhookRejection::where('provider', 'tiktok')->value('last_reason'))->toBe('bad_signature');

        ttPost($payload)->assertForbidden();
        expect(WebhookRejection::where('provider', 'tiktok')->value('last_reason'))->toBe('missing_signature');
    });

    it('refuses everything when no app secret is configured', function () {
        config(['services.tiktok.app_secret' => null]);
        $payload = ttReceive(['text' => ['body' => 'hi']]);
        ttPost($payload, ttSign(json_encode($payload, JSON_UNESCAPED_UNICODE)))->assertForbidden();
        expect(WebhookRejection::where('provider', 'tiktok')->value('last_reason'))->toBe('no_app_secret');
    });

    it('has no GET handshake: a GET is forbidden', function () {
        $this->get('/webhooks/tiktok?x=1')->assertForbidden();
    });

    it('ignores the signature age when the tolerance is zero', function () {
        Queue::fake();
        config(['services.tiktok.signature_tolerance_seconds' => 0]);
        $payload = ttReceive(['text' => ['body' => 'hi']]);
        ttPost($payload, ttSign(json_encode($payload, JSON_UNESCAPED_UNICODE), t: time() - 86400))->assertOk();
    });
});

describe('normalize', function () {
    it('reads a text message: the customer by unique_identifier, the account by user_openid, and remembers the conversation id', function () {
        $events = app(TikTokAdapter::class)->normalize(ttReceive(['text' => ['body' => 'بكام الفستان؟']]));

        expect($events)->toHaveCount(1);
        $e = $events[0];
        expect($e)->toBeInstanceOf(InboundMessageData::class)
            ->and($e->platform)->toBe(Platform::TikTok)
            ->and($e->channelExternalId)->toBe('open-id-levoile')
            ->and($e->customerExternalId)->toBe('uid-mona')
            ->and($e->customerName)->toBe('mona.adel')
            ->and($e->customerUsername)->toBe('mona.adel')
            ->and($e->externalMessageId)->toBe('msg-1')
            ->and($e->body)->toBe('بكام الفستان؟')
            ->and($e->occurredAt->getTimestampMs())->toBe(1761300000123)
            ->and($e->payload)->toBeNull();

        expect(TikTokConversation::idFor('open-id-levoile', 'uid-mona'))->toBe('conv-abc+/==');
    });

    it('maps an image to a media attachment to download, a sticker to its url, a shared post and an emoji picture to text or sticker', function () {
        $adapter = app(TikTokAdapter::class);

        $image = $adapter->normalize(ttReceive(['image' => ['media_id' => 'media-77=']], 'image'))[0];
        expect($image->body)->toBe('')->and($image->attachments)->toBe([['type' => 'image', 'id' => 'media-77=', 'mime_type' => 'image/jpeg']]);

        $sticker = $adapter->normalize(ttReceive(['sticker' => ['url' => 'https://p16.tiktokcdn.com/s.webp?x-expires=1']], 'sticker'))[0];
        expect($sticker->attachments)->toBe([['type' => 'sticker', 'url' => 'https://p16.tiktokcdn.com/s.webp?x-expires=1']]);

        $post = $adapter->normalize(ttReceive(['share_post' => ['embed_url' => 'https://www.tiktok.com/player/v1/123', 'video_id' => '123']], 'share_post'))[0];
        expect($post->body)->toContain('https://www.tiktok.com/player/v1/123');

        $emoji = $adapter->normalize(ttReceive(['emoji' => ['url' => 'https://p16.tiktokcdn.com/e.png']], 'emoji'))[0];
        expect($emoji->attachments[0]['type'])->toBe('sticker');

        $video = $adapter->normalize(ttReceive(['video' => ['media_id' => 'vid-1']], 'video'))[0];
        expect($video->attachments[0])->toBe(['type' => 'video', 'id' => 'vid-1', 'mime_type' => 'video/mp4']);
    });

    it('reads a tap on our Q&A card as the button title with our id as the payload', function () {
        $e = app(TikTokAdapter::class)->normalize(ttReceive([
            'text' => ['body' => 'المقاسات'],
            'reply_source_payload' => ['reply_source_msg_id' => 'msg-0', 'reply_source_unique_id' => 'flow:sizes'],
        ]))[0];

        expect($e->body)->toBe('المقاسات')->and($e->payload)->toBe('flow:sizes');
    });

    it('ignores reactions, templates, read marks, EU notices and unknown events', function () {
        $adapter = app(TikTokAdapter::class);

        expect($adapter->normalize(ttReceive(['reaction' => [['operation' => 'ADD', 'type' => 'EMOJI', 'emoji' => '❤️']]], 'reaction')))->toBe([])
            ->and($adapter->normalize(ttReceive(['template' => ['type' => 'qa_button_card']], 'template')))->toBe([])
            ->and($adapter->normalize(ttEvent('im_mark_read_msg', ['from' => 'mona.adel', 'conversation_id' => 'conv-abc', 'read' => ['last_read_timestamp' => '1761300000000']])))->toBe([])
            ->and($adapter->normalize(ttEvent('im_receive_msg_eu', ['to' => 'levoilestores', 'to_user' => ['id' => 'open-id-levoile', 'role' => 'business_account'], 'timestamp' => 1])))->toBe([])
            ->and($adapter->normalize(ttEvent('im_auto_message_config_update', ['x' => 1])))->toBe([])
            ->and($adapter->normalize(['hello' => 'world']))->toBe([]);
    });

    it('turns a reply sent from the TikTok app into an echo, and skips the echo of our own API sends', function () {
        $adapter = app(TikTokAdapter::class);
        $send = fn (string $source) => ttEvent('im_send_msg', [
            'from' => 'levoilestores', 'to' => 'mona.adel', 'unique_identifier' => 'uid-mona',
            'from_user' => ['id' => 'open-id-levoile', 'role' => 'business_account'], 'to_user' => ['id' => 'uid-mona', 'role' => 'personal_account'],
            'conversation_id' => 'conv-abc', 'message_id' => 'out-9', 'timestamp' => 1761300100000, 'type' => 'text', 'text' => ['body' => 'اتفضلي'],
            'message_tag' => ['source' => $source],
        ]);

        $echo = $adapter->normalize($send('APP'));
        expect($echo)->toHaveCount(1)->and($echo[0])->toBeInstanceOf(OutboundEchoData::class)
            ->and($echo[0]->customerExternalId)->toBe('uid-mona')
            ->and($echo[0]->externalMessageId)->toBe('out-9')
            ->and($echo[0]->body)->toBe('اتفضلي');

        expect($adapter->normalize($send('API')))->toBe([]);
    });

    it('reads a Click-to-Message ad referral and a tiktok.me link as attribution on an empty message', function () {
        $adapter = app(TikTokAdapter::class);
        $base = [
            'from' => 'mona.adel', 'to' => 'levoilestores', 'unique_identifier' => 'uid-mona',
            'from_user' => ['id' => 'uid-mona', 'role' => 'personal_account'], 'to_user' => ['id' => 'open-id-levoile', 'role' => 'business_account'],
            'conversation_id' => 'conv-abc', 'timestamp' => 1761300000000, 'is_follower' => false,
        ];

        $ad = $adapter->normalize(ttEvent('im_referral_msg', $base + ['referral' => ['source' => 'ad', 'ad' => ['advertiser_id' => '700', 'ad_id' => '1234567891234567', 'ad_name' => 'فستان الصيف', 'embed_url' => 'https://www.tiktok.com/player/v1/9', 'timestamp' => 1761299999000]]]))[0];
        expect($ad->body)->toBe('')->and($ad->externalMessageId)->toStartWith('referral:uid-mona:')
            ->and($ad->referral->isAd())->toBeTrue()->and($ad->referral->adId)->toBe('1234567891234567')->and($ad->referral->adTitle)->toBe('فستان الصيف');

        $link = $adapter->normalize(ttEvent('im_referral_msg', $base + ['referral' => ['source' => 'short_link', 'short_link' => ['ref' => 'bio-link', 'prefilled_message' => 'hi', 'prefilled_message_audit_status' => 'PASS']]]))[0];
        expect($link->referral->isAd())->toBeFalse()->and($link->referral->ref)->toBe('bio-link');

        expect(TikTokConversation::idFor('open-id-levoile', 'uid-mona'))->toBe('conv-abc');
    });
});

describe('ingest', function () {
    it('lands a DM in the inbox once, on the TikTok account, with the customer by unique_identifier, and drops a second delivery of the same message id', function () {
        $first = ttReceive(['text' => ['body' => 'بكام الفستان؟']]);
        $again = ttReceive(['text' => ['body' => 'بكام الفستان؟'], 'timestamp' => 1761300000999]); // a redelivery with a different body hash

        foreach ([$first, $again] as $payload) {
            ttPost($payload, ttSign(json_encode($payload, JSON_UNESCAPED_UNICODE)))->assertOk();
        }

        $identity = CustomerIdentity::where('platform', Platform::TikTok)->where('external_id', 'uid-mona')->sole();
        $conversation = Conversation::where('customer_id', $identity->customer_id)->sole();

        expect($identity->username)->toBe('mona.adel')
            ->and($conversation->channel_account_id)->toBe($this->account->id)
            ->and($conversation->platform)->toBe(Platform::TikTok)
            ->and(Message::where('direction', 'in')->count())->toBe(1)
            ->and(Message::where('direction', 'in')->first()->external_id)->toBe('msg-1')
            ->and(WebhookEvent::where('provider', 'tiktok')->where('status', 'processed')->count())->toBe(2);
    });

    it('ignores a DM for a TikTok account that is not connected here', function () {
        $payload = ttReceive(['text' => ['body' => 'hi']]) + [];
        $payload['user_openid'] = 'someone-elses-open-id';
        $payload['content'] = json_encode(array_merge(json_decode($payload['content'], true), ['to_user' => ['id' => 'someone-elses-open-id', 'role' => 'business_account']]));

        ttPost($payload, ttSign(json_encode($payload, JSON_UNESCAPED_UNICODE)))->assertOk();

        expect(Message::count())->toBe(0)->and(WebhookEvent::first()->status)->toBe('ignored');
    });
});
