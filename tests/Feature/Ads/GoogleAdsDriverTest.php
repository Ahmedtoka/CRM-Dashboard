<?php

use App\Ads\Platforms\AdPlatform;
use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\DriverFactory;
use App\Ads\Platforms\Google\GoogleAdsDriver;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const G_BASE = 'googleads.googleapis.com/v21';

function gFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/ads/{$name}.json")), true);
}

function gConnection(array $creds = []): AdPlatformConnection
{
    return AdPlatformConnection::factory()->google()->create(['credentials' => array_merge([
        'access_token' => 'GACCESS', 'developer_token' => 'GDEV',
    ], $creds)]);
}

function gAccount(?AdPlatformConnection $c = null): AdAccount
{
    return AdAccount::factory()->google()->create(['external_id' => '123-456-7890', 'connection_id' => ($c ?? gConnection())->id]);
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
});

it('lists accessible customers and reads each descriptive record', function () {
    Http::preventStrayRequests();
    Http::fake([
        G_BASE.'/customers:listAccessibleCustomers' => Http::response(gFixture('google_accessible')),
        G_BASE.'/customers/1234567890/googleAds:searchStream' => Http::response(gFixture('google_customer_1')),
        G_BASE.'/customers/2223334445/googleAds:searchStream' => Http::response(gFixture('google_customer_2')),
    ]);

    $accounts = app(GoogleAdsDriver::class)->accounts(gConnection());

    expect($accounts)->toHaveCount(2)
        ->and($accounts[0]->externalId)->toBe('1234567890')
        ->and($accounts[0]->name)->toBe('Le Voile Search')
        ->and($accounts[0]->currency)->toBe('EGP')
        ->and($accounts[0]->timezone)->toBe('Africa/Cairo')
        ->and($accounts[0]->status)->toBe('active')
        ->and($accounts[0]->balance)->toBeNull()
        ->and($accounts[1]->status)->toBe('disabled');
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer GACCESS') && $r->hasHeader('developer-token', 'GDEV')
        && ! str_contains($r->url(), 'GACCESS') && ! str_contains($r->url(), 'GDEV'));
});

it('maps google ads across searchStream batches', function () {
    Http::preventStrayRequests();
    Http::fake([G_BASE.'/customers/1234567890/googleAds:searchStream' => Http::response(gFixture('google_ads'))]);

    $ads = app(GoogleAdsDriver::class)->ads(gAccount());

    expect($ads)->toHaveCount(2)
        ->and($ads[0]->externalId)->toBe('33')
        ->and($ads[0]->name)->toBe('RSA one')
        ->and($ads[0]->status)->toBe('ENABLED')
        ->and($ads[0]->campaignId)->toBe('11')
        ->and($ads[0]->campaignName)->toBe('Search Sales')
        ->and($ads[0]->campaignStatus)->toBe('ENABLED')
        ->and($ads[0]->objective)->toBe('SEARCH')
        ->and($ads[0]->adSetId)->toBe('22')
        ->and($ads[0]->adSetName)->toBe('Abayas')
        ->and($ads[0]->type)->toBe('image')
        ->and($ads[0]->raw['adGroupAd']['ad']['finalUrls'])->toBe(['https://shop.test/abayas'])
        ->and($ads[1]->name)->toBe('34')
        ->and($ads[1]->status)->toBe('PAUSED');
    Http::assertSent(function ($r) {
        $q = $r->data()['query'] ?? '';

        return str_contains($q, 'FROM ad_group_ad') && str_contains($q, "ad_group_ad.status != 'REMOVED'");
    });
});

it('converts cost_micros to currency units and reads conversions', function () {
    Http::preventStrayRequests();
    Http::fake([G_BASE.'/customers/1234567890/googleAds:searchStream' => Http::response(gFixture('google_metrics'))]);

    $rows = app(GoogleAdsDriver::class)->dailyMetrics(gAccount(), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->adExternalId)->toBe('33')
        ->and($rows[0]->date)->toBe('2026-09-01')
        ->and($rows[0]->spend)->toBe(1234.56)
        ->and($rows[0]->impressions)->toBe(5400)
        ->and($rows[0]->clicks)->toBe(210)
        ->and($rows[0]->reach)->toBe(0)
        ->and($rows[0]->purchases)->toBe(3.0)
        ->and($rows[0]->purchaseValue)->toBe(2100.5)
        ->and($rows[0]->campaignId)->toBe('11')
        ->and($rows[0]->adSetName)->toBe('Abayas')
        ->and($rows[1]->spend)->toBe(0.0)
        ->and($rows[1]->purchaseValue)->toBe(0.0);
    Http::assertSent(fn ($r) => str_contains($r->data()['query'] ?? '', "segments.date BETWEEN '2026-09-01' AND '2026-09-02'"));
});

it('raises the google error message on 401', function () {
    Http::preventStrayRequests();
    Http::fake([G_BASE.'/*' => Http::response(gFixture('google_error'), 401)]);

    expect(fn () => app(GoogleAdsDriver::class)->accounts(gConnection()))
        ->toThrow(AdsApiException::class, 'Request had invalid authentication credentials.');
    expect(app(GoogleAdsDriver::class)->test(gConnection()))->toContain('invalid authentication credentials');
});

it('reads the error from a searchStream error array too', function () {
    Http::preventStrayRequests();
    Http::fake([G_BASE.'/*' => Http::response([['error' => ['code' => 403, 'message' => 'The caller does not have permission']]], 403)]);

    expect(fn () => app(GoogleAdsDriver::class)->ads(gAccount()))
        ->toThrow(AdsApiException::class, 'The caller does not have permission');
});

it('refreshes the oauth token once and caches it', function () {
    Http::preventStrayRequests();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(gFixture('google_token')),
        G_BASE.'/customers/1234567890/googleAds:searchStream' => Http::response(gFixture('google_ads')),
    ]);
    $c = gConnection(['refresh_token' => 'GREFRESH', 'client_id' => 'cid', 'client_secret' => 'GCSECRET']);
    $acc = gAccount($c);

    app(GoogleAdsDriver::class)->ads($acc);
    app(GoogleAdsDriver::class)->ads($acc);

    Http::assertSentCount(3);   // 1 refresh + 2 searches
    Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/token')
        && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'GREFRESH');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'searchStream') && $r->hasHeader('Authorization', 'Bearer ya29.fresh-token'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'searchStream') && $r->hasHeader('Authorization', 'Bearer GACCESS'));
});

it('does not call the token endpoint without refresh credentials', function () {
    Http::preventStrayRequests();
    Http::fake([G_BASE.'/*' => Http::response(gFixture('google_ads'))]);

    app(GoogleAdsDriver::class)->ads(gAccount());

    Http::assertSentCount(1);
});

it('sends login-customer-id with dashes stripped when configured', function () {
    Http::preventStrayRequests();
    Http::fake([G_BASE.'/*' => Http::response(gFixture('google_ads'))]);

    app(GoogleAdsDriver::class)->ads(gAccount(gConnection(['login_customer_id' => '999-888-7777'])));

    Http::assertSent(fn ($r) => $r->hasHeader('login-customer-id', '9998887777') && str_contains($r->url(), '/customers/1234567890/'));
});

it('surfaces a failed token refresh without leaking secrets', function () {
    Http::preventStrayRequests();
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
    $c = gConnection(['refresh_token' => 'GREFRESH', 'client_id' => 'cid', 'client_secret' => 'GCSECRET']);

    $message = app(GoogleAdsDriver::class)->test($c);

    expect($message)->toContain('expired or revoked')->not->toContain('GCSECRET')->not->toContain('GREFRESH');
});

it('never leaks google tokens in connection errors', function () {
    Http::preventStrayRequests();
    Http::fake([G_BASE.'/*' => fn () => throw new ConnectionException('cURL error 6: Authorization: Bearer GACCESS developer-token: GDEV')]);

    $message = app(GoogleAdsDriver::class)->test(gConnection());

    expect($message)->toContain('unreachable')->not->toContain('GACCESS')->not->toContain('GDEV');
});

it('returns no creative media for google', function () {
    Http::preventStrayRequests();

    expect(app(GoogleAdsDriver::class)->creativeMedia(gAccount(), ['33']))->toBe([]);
    Http::assertNothingSent();
});

it('wires live google through the factory', function () {
    config(['crm.ads.drivers.google' => 'live']);

    expect(app(DriverFactory::class)->for(AdPlatform::Google))->toBeInstanceOf(GoogleAdsDriver::class);
});
