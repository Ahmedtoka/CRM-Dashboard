<?php

use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdPlatformWriter;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Data\AdDraft;
use App\Ads\Platforms\Data\Identity;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\Meta\MetaAdsWriter;
use App\Models\AdAccount;
use App\Models\AdMaterialFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('round-trips on the fake writer: campaigns, upload, paused ad, status', function () {
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'fake']); // explicit: never depend on the environment default
    Cache::forget('ads-fake-writer');
    $acc = AdAccount::factory()->meta()->create(['external_id' => FakeAdsDriver::META_MAIN]);
    $writer = app(DriverFactory::class)->writer(AdPlatform::Meta);

    expect($writer)->toBeInstanceOf(AdPlatformWriter::class);

    $campaigns = $writer->liveCampaigns($acc);
    expect($campaigns)->toHaveCount(3)
        ->and($campaigns[0]->adSets)->toHaveCount(2)
        ->and($campaigns[0]->adSets[0])->toHaveKeys(['id', 'name', 'status']);

    $identity = $writer->identities($acc)[0];
    expect($identity)->toBeInstanceOf(Identity::class);

    $media = $writer->uploadMedia($acc, new AdMaterialFile(['mime' => 'video/mp4', 'disk' => 'local', 'path' => 'x.mp4']));
    expect($media->kind)->toBe('video')->and($writer->mediaReady($acc, $media))->toBeTrue();

    $draft = new AdDraft($campaigns[0]->adSets[0]['id'], 'M1 | Reel | C1', $identity, $media, 'text', 'head', 'SHOP_NOW', 'https://shop.test/p', 'utm_source=meta');
    $first = $writer->createPausedAd($acc, $draft);
    $second = $writer->createPausedAd($acc, $draft);
    expect($first)->toBe('fake_ad_1')->and($second)->toBe('fake_ad_2');

    $writer->setStatus($acc, 'ad', $first, 'active');
    $state = Cache::get('ads-fake-writer');
    expect($state['ads']['fake_ad_1']['name'])->toBe('M1 | Reel | C1')
        ->and($state['ads']['fake_ad_1']['status'])->toBe('paused')
        ->and($state['statuses'])->toBe([['level' => 'ad', 'id' => 'fake_ad_1', 'status' => 'active']]);
});

it('uses the fake writer unless the platform driver is live, and refuses Google', function () {
    config(['crm.ads.drivers.meta' => 'fake']);
    expect(app(DriverFactory::class)->writer(AdPlatform::Meta))->toBeInstanceOf(FakeAdsDriver::class);

    app(DriverFactory::class)->writer(AdPlatform::Google);
})->throws(AdsApiException::class, 'Google Ads publishing is not supported');

it('returns the live Meta writer when meta is live', function () {
    config(['crm.ads.drivers.meta' => 'live']);

    expect(app(DriverFactory::class)->writer(AdPlatform::Meta))->toBeInstanceOf(MetaAdsWriter::class);
});
