<?php

use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Platforms\Meta\MetaAdsDriver;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

it('returns deterministic fake data', function () {
    Http::preventStrayRequests();
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_950240346866068']);
    $driver = new FakeAdsDriver;
    $from = CarbonImmutable::parse('2026-09-01');
    $to = CarbonImmutable::parse('2026-09-03');

    expect($driver->ads($acc))->toHaveCount(18)
        ->and($driver->ads($acc))->toEqual($driver->ads($acc))
        ->and($driver->dailyMetrics($acc, $from, $to))->toEqual($driver->dailyMetrics($acc, $from, $to))
        ->and($driver->dailyMetrics($acc, $from, $to))->toHaveCount(18 * 3);

    $wide = $driver->dailyMetrics($acc, $from, $to);
    $narrow = $driver->dailyMetrics($acc, $to, $to->addDay());
    $shared = array_values(array_filter($wide, fn ($r) => $r->date === $to->toDateString()));
    expect(array_slice($narrow, 0, 18))->toEqual($shared);

    $types = collect($driver->ads($acc));
    expect($types->firstWhere('type', 'video')->videoId)->not->toBeNull()
        ->and($types->firstWhere('type', 'carousel')->carousel)->toHaveCount(3);

    $m = $driver->dailyMetrics($acc, $from, $from)[0];
    expect($m->spend)->toBeGreaterThanOrEqual(300)->toBeLessThanOrEqual(3000)
        ->and($m->clicks)->toBeLessThanOrEqual($m->impressions);
    Http::assertNothingSent();
});

it('lists the fixed fake accounts per platform', function () {
    $driver = new FakeAdsDriver;
    $names = fn (string $p) => array_map(
        fn ($a) => $a->name,
        $driver->accounts(AdPlatformConnection::factory()->state(['platform' => $p])->create()),
    );

    expect($names('meta'))->toBe(['Cloting', 'Lv Main', 'Lv Main 22'])
        ->and($names('tiktok'))->toBe(['Le Voile TikTok'])
        ->and($names('google'))->toBe(['Le Voile Google']);
});

it('uses TikTok and Google campaign names', function () {
    $tt = AdAccount::factory()->tiktok()->create();
    $g = AdAccount::factory()->google()->create();
    $driver = new FakeAdsDriver;

    expect($driver->ads($tt)[0]->campaignName)->toBe('TikTok Main')
        ->and($driver->ads($g)[0]->campaignName)->toBe('Google Search');
});

it('goes live only on the exact value live', function () {
    config(['crm.ads.drivers.meta' => 'fake']);
    expect(app(DriverFactory::class)->for(AdPlatform::Meta))->toBeInstanceOf(FakeAdsDriver::class);
    config(['crm.ads.drivers.meta' => 'LIVE ']);
    expect(app(DriverFactory::class)->for(AdPlatform::Meta))->toBeInstanceOf(FakeAdsDriver::class);
    config(['crm.ads.drivers.meta' => 'live']);
    expect(app(DriverFactory::class)->for(AdPlatform::Meta))->toBeInstanceOf(MetaAdsDriver::class);
});
