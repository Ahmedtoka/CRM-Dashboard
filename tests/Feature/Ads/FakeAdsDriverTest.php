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
    $acc = AdAccount::factory()->meta()->create(['external_id' => FakeAdsDriver::META_MAIN_22]);
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

    expect($names('meta'))->toBe(['Cloting (تجريبي)', 'Lv Main (تجريبي)', 'Lv Main 22 (تجريبي)'])
        ->and($names('tiktok'))->toBe(['Le Voile TikTok (تجريبي)'])
        ->and($names('google'))->toBe(['Le Voile Google (تجريبي)']);
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

it('defaults the drivers to live on production and fake elsewhere, env still overriding', function () {
    $load = function (array $env): array {
        $keys = ['APP_ENV', 'CRM_ADS_META_DRIVER', 'CRM_ADS_TIKTOK_DRIVER', 'CRM_ADS_GOOGLE_DRIVER'];
        $saved = [];
        foreach ($keys as $k) {
            $saved[$k] = [$_SERVER[$k] ?? null, $_ENV[$k] ?? null];
            unset($_SERVER[$k], $_ENV[$k]);
            if (array_key_exists($k, $env)) {
                $_SERVER[$k] = $_ENV[$k] = $env[$k];
            }
        }
        try {
            return (require config_path('crm.php'))['ads']['drivers'];
        } finally {
            foreach ($saved as $k => [$server, $e]) {
                unset($_SERVER[$k], $_ENV[$k]);
                if ($server !== null) {
                    $_SERVER[$k] = $server;
                }
                if ($e !== null) {
                    $_ENV[$k] = $e;
                }
            }
        }
    };

    expect($load(['APP_ENV' => 'production']))->toBe(['meta' => 'live', 'tiktok' => 'live', 'google' => 'live'])
        ->and($load(['APP_ENV' => 'local']))->toBe(['meta' => 'fake', 'tiktok' => 'fake', 'google' => 'fake'])
        ->and($load(['APP_ENV' => 'production', 'CRM_ADS_TIKTOK_DRIVER' => 'fake'])['tiktok'])->toBe('fake')
        ->and($load(['APP_ENV' => 'local', 'CRM_ADS_META_DRIVER' => 'live'])['meta'])->toBe('live');
});

it('uses obviously fake account ids', function () {
    $driver = new FakeAdsDriver;
    $ids = fn (string $p) => array_map(fn ($a) => $a->externalId, $driver->accounts(AdPlatformConnection::factory()->state(['platform' => $p])->create()));

    expect($ids('meta'))->toBe(['act_demo_cloting', 'act_demo_main', 'act_demo_main22'])
        ->and($ids('tiktok'))->toBe(['tt_demo_1'])
        ->and($ids('google'))->toBe(['gg-demo-1']);
});
