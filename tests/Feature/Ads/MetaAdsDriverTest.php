<?php

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Meta\MetaAdsDriver;
use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\SecretScrubber;
use AppAdsPlatformsTokenInvalid;
use App\Ads\Sync\AdsSyncService;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
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

it('asks meta for ads paused by their campaign or ad set, in review or with issues, but not archived', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v23.0/act_1/ads*' => Http::response(['data' => []])]);
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_1', 'connection_id' => metaConnection()->id]);

    app(MetaAdsDriver::class)->ads($acc);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'act_1/ads')) {
            return false;
        }
        $statuses = json_decode($request['effective_status'], true);

        return $statuses === ['ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'DISAPPROVED', 'WITH_ISSUES', 'PENDING_REVIEW', 'IN_PROCESS']
            && ! in_array('ARCHIVED', $statuses, true) && ! in_array('DELETED', $statuses, true);
    });
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
    expect(fn () => app(MetaAdsDriver::class)->test(metaConnection()))->toThrow(TokenInvalid::class, 'Invalid OAuth access token.');
});

it('never leaks the access token in connection errors', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('cURL error 6: https://graph.facebook.com/v23.0/me?access_token=SECRET123&fields=id')]);
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'SECRET123']]);

    $message = app(MetaAdsDriver::class)->test($c);

    expect($message)->toContain('unreachable')->not->toContain('SECRET123');
});

it('sends the token as a bearer header and not in the query', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);
    app(MetaAdsDriver::class)->accounts(metaConnection());

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok') && ! str_contains($r->url(), 'access_token'));
});

it('fails loudly instead of truncating after too many pages', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [['id' => 'act_1']], 'paging' => ['next' => 'https://graph.facebook.com/more?access_token=SECRET123&after=x']])]);

    expect(fn () => app(MetaAdsDriver::class)->accounts(metaConnection()))
        ->toThrow(AdsApiException::class, 'narrow the date range');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'SECRET123'));
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

it('scrubs json-style, query and header secrets in one shared helper', function () {
    $text = '{"error":"bad","access_token":"EAAB123","refresh_token": "r-9"} url?access_token=Q1&x=1 '
        .'Authorization: Bearer B2 developer-token: D3 Access-Token: T4 raw-SECRET';

    $clean = SecretScrubber::scrub($text, ['raw-SECRET']);

    expect($clean)->not->toContain('EAAB123')->not->toContain('r-9')->not->toContain('Q1')
        ->not->toContain('B2')->not->toContain('D3')->not->toContain('T4')->not->toContain('raw-SECRET')
        ->toContain('"access_token":"***"')->toContain('access_token=***&x=1')
        ->and(AdsSyncService::scrub('{"access_token":"EAAB123"}'))->not->toContain('EAAB123');
});

it('keeps a json token out of a meta error message', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad request {"access_token":"LEAKME"}', 'code' => 100]], 400)]);
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_1', 'connection_id' => metaConnection()->id]);

    try {
        app(MetaAdsDriver::class)->ads($acc);
        $this->fail('expected an exception');
    } catch (AdsApiException $e) {
        expect($e->getMessage())->not->toContain('LEAKME');
    }
});

it('asks again with a smaller page when meta says the page is too large, and keeps it for the next pages', function () {
    Http::preventStrayRequests();
    $tooMuch = Http::response(['error' => ['message' => "Please reduce the amount of data you're asking for, then retry your request", 'code' => 1]], 500);
    Http::fake([
        'graph.facebook.com/v23.0/act_1/ads*' => Http::sequence()->pushResponse($tooMuch)->pushResponse($tooMuch)
            ->push(['data' => [['id' => 'ad_1', 'name' => 'A']], 'paging' => ['next' => 'https://graph.facebook.com/v23.0/act_1/ads?limit=50&after=c1']])
            ->push(['data' => [['id' => 'ad_2', 'name' => 'B']]]),
    ]);
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_1', 'connection_id' => metaConnection()->id]);

    $ads = app(MetaAdsDriver::class)->ads($acc);

    expect(collect($ads)->pluck('externalId')->all())->toBe(['ad_1', 'ad_2']);
    $limits = collect(Http::recorded())->map(fn ($pair) => (int) ($pair[0]->data()['limit'] ?? 0) ?: (int) preg_replace('/.*[?&]limit=(\d+).*/', '$1', $pair[0]->url()))->all();
    expect($limits)->toBe([50, 25, 12, 12]);
});

it('gives up on a too-large page once the limit is down to five', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => "Please reduce the amount of data you're asking for, then retry your request", 'code' => 1]], 500)]);
    $acc = AdAccount::factory()->meta()->create(['external_id' => 'act_1', 'connection_id' => metaConnection()->id]);

    expect(fn () => app(MetaAdsDriver::class)->ads($acc))->toThrow(AdsApiException::class, 'reduce the amount of data');
    Http::assertSentCount(5); // 50, 25, 12, 6, 5
});

it('treats the ad-account call limit (80004) as a rate limit, so the job comes back later', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'There have been too many calls to this ad-account. Wait a bit and try again.', 'code' => 80004]], 400)]);

    expect(fn () => app(MetaAdsDriver::class)->accounts(metaConnection()))->toThrow(RateLimited::class);
});
