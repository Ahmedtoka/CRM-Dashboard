<?php

use App\Channels\Adapters\InstagramAdapter;
use App\Channels\Adapters\MetaGraphClient;
use App\Channels\Ads\AdLookup;
use App\Channels\Data\InboundCommentData;
use App\Comments\CommentIngestor;
use App\Comments\Jobs\ClassifyAdPost;
use App\Enums\Platform;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Post;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake();
    Http::preventStrayRequests();
    Cache::flush();
    BotSetting::current()->update(['enabled' => false]);
});

it('reads the ad off an instagram comment webhook and keeps it on the post', function () {
    Queue::fake([ClassifyAdPost::class]);
    ChannelAccount::factory()->create(['platform' => Platform::Instagram, 'external_id' => 'IG1', 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);

    $events = app(InstagramAdapter::class)->normalize(['object' => 'instagram', 'entry' => [['id' => 'IG1', 'time' => 1757671200, 'changes' => [[
        'field' => 'comments',
        'value' => ['id' => 'C1', 'text' => 'بكام؟', 'from' => ['id' => 'U1', 'username' => 'sara'], 'media' => ['id' => 'M1', 'media_product_type' => 'AD', 'ad_id' => '120001', 'ad_title' => 'كولكشن الصيف']],
    ]]]]]);

    expect($events[0])->toBeInstanceOf(InboundCommentData::class)->and($events[0]->isAd)->toBeTrue()->and($events[0]->adId)->toBe('120001');

    app(CommentIngestor::class)->ingest($events[0]);

    $post = Post::sole();
    expect($post->is_ad)->toBeTrue()->and($post->ad_id)->toBe('120001')->and($post->ad_title)->toBe('كولكشن الصيف');
    Queue::assertPushed(ClassifyAdPost::class, fn ($job) => $job->postId === $post->id);
});

it('marks an unpublished facebook post as an ad and names its campaign from the marketing api', function () {
    $account = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1', 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $post = Post::create(['channel_account_id' => $account->id, 'platform' => Platform::Facebook, 'external_id' => 'PAGE1_777', 'is_ad' => false]);

    Http::fake([
        'graph.facebook.com/*/PAGE1_777*' => Http::response(['is_published' => false, 'permalink_url' => 'https://facebook.com/PAGE1/posts/777', 'message' => 'كولكشن الصيف وصل']),
        'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [['id' => 'act_1']]]),
        'graph.facebook.com/*/act_1/ads*' => Http::response(['data' => [
            ['id' => '120009', 'name' => 'Old ad', 'adset' => ['name' => 'Old'], 'campaign' => ['name' => 'Old campaign'], 'creative' => ['effective_object_story_id' => 'PAGE1_1']],
            ['id' => '120001', 'name' => 'Summer - Video 1', 'adset' => ['name' => 'Women 25-44'], 'campaign' => ['name' => 'Summer Launch'], 'creative' => ['effective_object_story_id' => 'PAGE1_777']],
        ], 'paging' => ['cursors' => ['after' => '']]]),
    ]);

    (new ClassifyAdPost($post->id))->handle(app(MetaGraphClient::class), app(AdLookup::class));

    $post->refresh();
    expect($post->is_ad)->toBeTrue()
        ->and($post->ad_id)->toBe('120001')
        ->and($post->ad_campaign_name)->toBe('Summer Launch')
        ->and($post->ad_adset_name)->toBe('Women 25-44')
        ->and($post->permalink)->toBe('https://facebook.com/PAGE1/posts/777')
        ->and($post->ad_checked_at)->not->toBeNull();

    // The ads index is cached: a second post on the same account costs no new ads call.
    $other = Post::create(['channel_account_id' => $account->id, 'platform' => Platform::Facebook, 'external_id' => 'PAGE1_1', 'is_ad' => false]);
    Http::fake(['graph.facebook.com/*/PAGE1_1*' => Http::response(['is_published' => true])]);
    (new ClassifyAdPost($other->id))->handle(app(MetaGraphClient::class), app(AdLookup::class));
    expect($other->refresh()->ad_campaign_name)->toBe('Old campaign')->and($other->is_ad)->toBeTrue();
    Http::assertSentCount(1); // only the post itself: the ad accounts and ads index came from the cache
});

it('leaves a plain post alone when the token cannot read ads', function () {
    $account = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1', 'driver' => 'live', 'credentials' => ['access_token' => 'tok']]);
    $post = Post::create(['channel_account_id' => $account->id, 'platform' => Platform::Facebook, 'external_id' => 'PAGE1_5', 'is_ad' => false]);
    Http::fake([
        'graph.facebook.com/*/PAGE1_5*' => Http::response(['is_published' => true]),
        'graph.facebook.com/*/me/adaccounts*' => Http::response(['error' => ['message' => '(#200) ads_read required', 'code' => 200]], 403),
    ]);

    (new ClassifyAdPost($post->id))->handle(app(MetaGraphClient::class), app(AdLookup::class));

    expect($post->refresh()->is_ad)->toBeFalse()->and($post->ad_checked_at)->not->toBeNull();
});
