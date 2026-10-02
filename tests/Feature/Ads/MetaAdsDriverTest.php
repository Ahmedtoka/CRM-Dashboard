<?php

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Meta\MetaAdsDriver;
use App\Ads\Platforms\RateLimited;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function adsFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/ads/{$name}.json")), true);
}

function metaConnection(): AdPlatformConnection
{
    return AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'tok']]);
}

it('maps meta ad-level insights with the purchase action priority', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v23.0/act_1/insights*' => Http::response(adsFixture('meta_insights'))]);
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_1', 'connection_id' => metaConnection()->id]);

    $rows = app(MetaAdsDriver::class)->dailyMetrics($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->purchases)->toBe(3.0)
        ->and($rows[0]->purchaseValue)->toBe(1350.0)
        ->and($rows[0]->spend)->toBe(450.5)
        ->and($rows[0]->date)->toBe('2026-09-01')
        ->and($rows[1]->purchases)->toBe(2.0);
});

it('follows paging.next for ads and detects types', function () {
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/v23.0/act_1/ads*' => Http::response(adsFixture('meta_ads_page1')),
        'graph.facebook.com/page2*' => Http::response(adsFixture('meta_ads_page2')),
    ]);
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_1', 'connection_id' => metaConnection()->id]);

    $ads = app(MetaAdsDriver::class)->ads($acc);

    expect($ads)->toHaveCount(3)
        ->and($ads[0]->type)->toBe('video')
        ->and($ads[0]->videoId)->toBe('vid_1')
        ->and($ads[0]->campaignName)->toBe('Sales')
        ->and($ads[1]->type)->toBe('carousel')
        ->and($ads[1]->carousel)->toHaveCount(2)
        ->and($ads[1]->carousel[0]['link'])->toBe('https://shop.test/a')
        ->and($ads[2]->type)->toBe('image')
        ->and($ads[2]->headline)->toBe('Summer drop')
        ->and($ads[2]->objectStoryId)->toBe('1_2');
});

it('lists ad accounts with balance in currency units', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v23.0/me/adaccounts*' => Http::response(adsFixture('meta_accounts'))]);

    $accounts = app(MetaAdsDriver::class)->accounts(metaConnection());

    expect($accounts)->toHaveCount(2)
        ->and($accounts[0]->externalId)->toBe('act_111')
        ->and($accounts[0]->balance)->toBe(123.45)
        ->and($accounts[0]->status)->toBe('active')
        ->and($accounts[1]->status)->toBe('disabled');
});

it('raises a readable error when meta answers with an error', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 400)]);

    expect(fn () => app(MetaAdsDriver::class)->accounts(metaConnection()))
        ->toThrow(AdsApiException::class, 'Invalid OAuth access token.');
    expect(app(MetaAdsDriver::class)->test(metaConnection()))->toContain('Invalid OAuth access token.');
});

it('throws RateLimited when usage is above 85 percent', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []], 200, [
        'x-business-use-case-usage' => json_encode(['123' => [['call_count' => 90, 'total_time' => 10, 'total_cputime' => 5]]]),
    ])]);

    expect(fn () => app(MetaAdsDriver::class)->accounts(metaConnection()))->toThrow(RateLimited::class);
});

it('fetches creative media in batches', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v23.0*' => Http::response(adsFixture('meta_batch'))]);
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_1', 'connection_id' => metaConnection()->id]);

    $media = app(MetaAdsDriver::class)->creativeMedia($acc, ['ad_1', 'ad_2']);

    expect($media)->toHaveCount(2)
        ->and($media[0]->previewUrl)->toBe('https://www.facebook.com/ads/api/preview_iframe.php?d=abc&t=1')
        ->and($media[0]->previewHtml)->toContain('iframe')
        ->and($media[1]->previewUrl)->toBeNull();
});
