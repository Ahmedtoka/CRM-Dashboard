<?php

use App\Channels\Adapters\MessengerAdapter;
use App\Channels\Adapters\MetaGraphClient;
use App\Channels\Data\InboundMessageData;
use App\Channels\MetaPageSubscriber;
use App\Enums\ConversationSource;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Http\Resources\ConversationResource;
use App\Inbox\InboxIngestor;
use App\Inbox\Jobs\EnrichAdAttribution;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    BotSetting::current()->update(['enabled' => false]);
});

it('reads the ad referral off a click-to-messenger message, a postback and a bare referral event', function () {
    $referral = ['ref' => 'summer', 'source' => 'ADS', 'type' => 'OPEN_THREAD', 'ad_id' => '120001', 'ads_context_data' => ['ad_title' => 'كولكشن الصيف 🌸', 'photo_url' => 'https://scontent.example/ad.jpg', 'post_id' => '459028320806456_1']];
    $events = app(MessengerAdapter::class)->normalize(['object' => 'page', 'entry' => [['id' => 'PAGE1', 'time' => 1757671200000, 'messaging' => [
        ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'PAGE1'], 'timestamp' => 1757671200000, 'message' => ['mid' => 'mid.1', 'text' => 'بكام؟', 'referral' => $referral]],
        ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'PAGE1'], 'timestamp' => 1757671201000, 'postback' => ['title' => 'ابدأ', 'payload' => 'menu:main_menu', 'referral' => $referral]],
        ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'PAGE1'], 'timestamp' => 1757671202000, 'referral' => ['ref' => 'bio', 'source' => 'SHORTLINK', 'type' => 'OPEN_THREAD']],
    ]]]]);

    expect($events)->toHaveCount(3)
        ->and($events[0]->referral->adId)->toBe('120001')
        ->and($events[0]->referral->adTitle)->toBe('كولكشن الصيف 🌸')
        ->and($events[1]->referral->adId)->toBe('120001')
        ->and($events[2])->toBeInstanceOf(InboundMessageData::class)
        ->and($events[2]->body)->toBe('')
        ->and($events[2]->referral->source)->toBe('SHORTLINK')
        ->and($events[2]->referral->ref)->toBe('bio')
        ->and(MetaPageSubscriber::FIELDS)->toContain('messaging_referrals');
});

it('keeps the first ad on the conversation, marks the source, writes the thread line and looks the campaign up', function () {
    Queue::fake([EnrichAdAttribution::class]);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null]);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1', 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $referral = ['source' => 'ADS', 'type' => 'OPEN_THREAD', 'ad_id' => '120001', 'ads_context_data' => ['ad_title' => 'كولكشن الصيف 🌸']];

    $events = app(MessengerAdapter::class)->normalize(['object' => 'page', 'entry' => [['id' => 'PAGE1', 'time' => 1757671200000, 'messaging' => [
        ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'PAGE1'], 'timestamp' => 1757671200000, 'message' => ['mid' => 'mid.1', 'text' => 'بكام؟', 'referral' => $referral]],
    ]]]]);
    app(InboxIngestor::class)->ingestMessage($events[0]);

    $c = Conversation::sole();
    expect($c->ad_id)->toBe('120001')
        ->and($c->ad_title)->toBe('كولكشن الصيف 🌸')
        ->and($c->source)->toBe(ConversationSource::Ad)
        ->and($c->ad_attributed_at)->not->toBeNull()
        ->and(Message::where('sender_type', SenderType::System->value)->value('body'))->toBe('📣 العميلة جات من إعلان: كولكشن الصيف 🌸');
    Queue::assertPushed(EnrichAdAttribution::class, fn ($job) => $job->conversationId === $c->id);

    // A second ad later on does not overwrite the first touch.
    $later = app(MessengerAdapter::class)->normalize(['object' => 'page', 'entry' => [['id' => 'PAGE1', 'time' => 1757671300000, 'messaging' => [
        ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'PAGE1'], 'timestamp' => 1757671300000, 'message' => ['mid' => 'mid.2', 'text' => 'والشحن؟', 'referral' => ['source' => 'ADS', 'ad_id' => '120002', 'ads_context_data' => ['ad_title' => 'خصم']]]],
    ]]]]);
    app(InboxIngestor::class)->ingestMessage($later[0]);
    expect($c->fresh()->ad_id)->toBe('120001');

    // A referral-only event does not wake the bot.
    $bare = app(MessengerAdapter::class)->normalize(['object' => 'page', 'entry' => [['id' => 'PAGE1', 'time' => 1757671400000, 'messaging' => [
        ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'PAGE1'], 'timestamp' => 1757671400000, 'referral' => ['source' => 'ADS', 'ad_id' => '120003', 'type' => 'OPEN_THREAD']],
    ]]]]);
    $runs = BotRun::count();
    $due = $c->fresh()->bot_due_at?->toIso8601String();
    app(InboxIngestor::class)->ingestMessage($bare[0]);
    expect(BotRun::count())->toBe($runs)->and($c->fresh()->bot_due_at?->toIso8601String())->toBe($due);

    // The Marketing API lookup fills the names; a refused lookup leaves the title in place.
    Http::fake(['graph.facebook.com/*/120001*' => Http::response(['name' => 'Summer - Video 1', 'adset' => ['name' => 'Women 25-44'], 'campaign' => ['name' => 'Summer Launch']])]);
    (new EnrichAdAttribution($c->id))->handle(app(MetaGraphClient::class));
    expect($c->fresh()->ad_campaign_name)->toBe('Summer Launch')->and($c->fresh()->ad_adset_name)->toBe('Women 25-44')->and($c->fresh()->ad_name)->toBe('Summer - Video 1');

    $json = (new ConversationResource($c->fresh()))->toArray(request());
    expect($json['ad']['campaign'])->toBe('Summer Launch')->and($json['ad']['title'])->toBe('كولكشن الصيف 🌸');
});
