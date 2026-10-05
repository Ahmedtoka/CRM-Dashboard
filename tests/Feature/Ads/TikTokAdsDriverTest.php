<?php

use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\TikTok\TikTokAdsDriver;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const TT_BASE = 'business-api.tiktok.com/open_api/v1.3';

function ttFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/ads/{$name}.json")), true);
}

function ttConnection(array $extra = []): AdPlatformConnection
{
    return AdPlatformConnection::factory()->tiktok()->create(['credentials' => array_merge([
        'access_token' => 'TTSECRET', 'advertiser_ids' => ['7000000000001', '7000000000002'],
    ], $extra)]);
}

function ttAccount(): AdAccount
{
    return AdAccount::factory()->tiktok()->create(['external_id' => '7000000000001', 'connection_id' => ttConnection()->id]);
}

it('maps tiktok advertisers from advertiser/info', function () {
    Http::preventStrayRequests();
    Http::fake([TT_BASE.'/advertiser/info/*' => Http::response(ttFixture('tiktok_advertiser_info'))]);

    $accounts = app(TikTokAdsDriver::class)->accounts(ttConnection());

    expect($accounts)->toHaveCount(2)
        ->and($accounts[0]->externalId)->toBe('7000000000001')
        ->and($accounts[0]->name)->toBe('Le Voile TikTok')
        ->and($accounts[0]->currency)->toBe('EGP')
        ->and($accounts[0]->timezone)->toBe('Africa/Cairo')
        ->and($accounts[0]->status)->toBe('active')
        ->and($accounts[0]->balance)->toBe(1520.75)
        ->and($accounts[1]->status)->toBe('disabled');
    Http::assertSent(fn ($r) => $r->hasHeader('Access-Token', 'TTSECRET')
        && ! str_contains($r->url(), 'TTSECRET')
        && str_contains(urldecode($r->url()), '["7000000000001","7000000000002"]'));
});

it('pages through ads using page_info.total_page and maps types', function () {
    Http::preventStrayRequests();
    Http::fake(function ($r) {
        if (! str_contains($r->url(), '/ad/get/')) {
            return Http::response([], 500);
        }
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return Http::response(ttFixture(($q['page'] ?? '1') === '1' ? 'tiktok_ads_page1' : 'tiktok_ads_page2'));
    });

    $ads = app(TikTokAdsDriver::class)->ads(ttAccount());

    expect($ads)->toHaveCount(2)
        ->and($ads[0]->externalId)->toBe('1800000000001')
        ->and($ads[0]->type)->toBe('video')
        ->and($ads[0]->videoId)->toBe('v10033g50000abc')
        ->and($ads[0]->campaignName)->toBe('Sales')
        ->and($ads[0]->adSetName)->toBe('Women 18-34')
        ->and($ads[0]->status)->toBe('ENABLE')
        ->and($ads[0]->effectiveStatus)->toBe('AD_STATUS_DELIVERY_OK')
        ->and($ads[0]->body)->toBe('New abayas are here')
        ->and($ads[0]->raw['landing_page_url'])->toBe('https://shop.test/abayas')
        ->and($ads[1]->type)->toBe('image')
        ->and($ads[1]->videoId)->toBeNull()
        ->and($ads[1]->status)->toBe('DISABLE');
    Http::assertSentCount(2);
});

it('maps tiktok daily metrics with the stat day trimmed to a date', function () {
    Http::preventStrayRequests();
    Http::fake([TT_BASE.'/report/integrated/get/*' => Http::response(ttFixture('tiktok_report'))]);

    $rows = app(TikTokAdsDriver::class)->dailyMetrics(ttAccount(), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->adExternalId)->toBe('1800000000001')
        ->and($rows[0]->date)->toBe('2026-09-01')
        ->and($rows[0]->spend)->toBe(250.4)
        ->and($rows[0]->impressions)->toBe(10400)
        ->and($rows[0]->clicks)->toBe(310)
        ->and($rows[0]->linkClicks)->toBe(310)
        ->and($rows[0]->reach)->toBe(8200)
        ->and($rows[0]->purchases)->toBe(4.0)
        ->and($rows[0]->purchaseValue)->toBe(1800.0)
        ->and($rows[0]->campaignName)->toBe('Sales')
        ->and($rows[0]->adSetId)->toBe('1710000000001')
        ->and($rows[1]->date)->toBe('2026-09-02');
    Http::assertSent(function ($r) {
        $u = urldecode($r->url());

        return str_contains($u, 'start_date=2026-09-01') && str_contains($u, 'end_date=2026-09-02')
            && str_contains($u, 'data_level=AUCTION_AD') && str_contains($u, '["ad_id","stat_time_day"]');
    });
});

it('fetches tiktok video previews and covers', function () {
    Http::preventStrayRequests();
    Http::fake([TT_BASE.'/file/video/ad/info/*' => Http::response(ttFixture('tiktok_video_info'))]);
    $acc = ttAccount();
    Ad::factory()->create(['ad_account_id' => $acc->id, 'external_id' => '1800000000001', 'raw' => ['video_id' => 'v10033g50000abc']]);
    Ad::factory()->create(['ad_account_id' => $acc->id, 'external_id' => '1800000000002', 'raw' => ['video_id' => '']]);

    $media = app(TikTokAdsDriver::class)->creativeMedia($acc, ['1800000000001', '1800000000002']);

    expect($media)->toHaveCount(2)
        ->and($media[0]->videoUrl)->toBe('https://v16.tiktokcdn.test/video/abc.mp4?sig=1')
        ->and($media[0]->previewUrl)->toBe('https://v16.tiktokcdn.test/video/abc.mp4?sig=1')
        ->and($media[0]->thumbnailUrl)->toBe('https://p16.tiktokcdn.test/cover/abc.jpeg')
        ->and($media[1]->videoUrl)->toBeNull();
});

it('raises the tiktok message on a non-zero code', function () {
    Http::preventStrayRequests();
    Http::fake([TT_BASE.'/*' => Http::response(ttFixture('tiktok_error'))]);

    expect(fn () => app(TikTokAdsDriver::class)->accounts(ttConnection()))
        ->toThrow(AdsApiException::class, 'Access token is incorrect or has been revoked.');
    expect(app(TikTokAdsDriver::class)->test(ttConnection()))->toContain('Access token is incorrect');
});

it('test returns null when advertiser info works', function () {
    Http::preventStrayRequests();
    Http::fake([TT_BASE.'/advertiser/info/*' => Http::response(ttFixture('tiktok_advertiser_info'))]);

    expect(app(TikTokAdsDriver::class)->test(ttConnection()))->toBeNull();
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), '["7000000000001"]'));
});

it('never leaks the tiktok token in connection errors', function () {
    Http::preventStrayRequests();
    Http::fake([TT_BASE.'/*' => fn () => throw new ConnectionException('cURL error 6: could not resolve; Access-Token: TTSECRET')]);

    $message = app(TikTokAdsDriver::class)->test(ttConnection());

    expect($message)->toContain('unreachable')->not->toContain('TTSECRET');
});

it('fails loudly instead of truncating after too many tiktok pages', function () {
    Http::preventStrayRequests();
    Http::fake([TT_BASE.'/ad/get/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['list' => [], 'page_info' => ['page' => 1, 'total_page' => 100000]]])]);

    expect(fn () => app(TikTokAdsDriver::class)->ads(ttAccount()))
        ->toThrow(AdsApiException::class, 'narrow the date range');
});

it('wires live tiktok through the factory', function () {
    config(['crm.ads.drivers.tiktok' => 'live']);

    expect(app(DriverFactory::class)->for(AdPlatform::Tiktok))->toBeInstanceOf(TikTokAdsDriver::class);
});

it('treats a non-envelope 200 response as an error', function (mixed $body) {
    Http::preventStrayRequests();
    Http::fake([TT_BASE.'/*' => Http::response($body, 200)]);

    expect(fn () => app(TikTokAdsDriver::class)->ads(ttAccount()))
        ->toThrow(AdsApiException::class, 'unexpected response (HTTP 200)');
})->with(['<html>proxy</html>', '', '{"foo":1}']);

it('skips tiktok ad rows without an ad_id', function () {
    Http::preventStrayRequests();
    Http::fake([TT_BASE.'/ad/get/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['list' => [['ad_name' => 'ghost'], ['ad_id' => '5', 'ad_name' => 'ok']], 'page_info' => ['page' => 1, 'total_page' => 1]]])]);

    expect(app(TikTokAdsDriver::class)->ads(ttAccount()))->toHaveCount(1);
});

it('chunks advertiser ids by 100', function () {
    Http::preventStrayRequests();
    Http::fake([TT_BASE.'/advertiser/info/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['list' => []]])]);

    app(TikTokAdsDriver::class)->accounts(ttConnection(['advertiser_ids' => array_map('strval', range(1, 150))]));

    Http::assertSentCount(2);
});
