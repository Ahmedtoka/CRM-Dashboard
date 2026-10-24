<?php

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\Meta\MetaAdsWriter;
use App\Ads\Platforms\TikTok\TikTokAdsWriter;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Round 3: the exact platform requests of a daily budget change (Http faked: nothing here can reach Meta or TikTok). */
function r3TikTokAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->tiktok()->create(['credentials' => ['access_token' => 'tt-secret', 'advertiser_ids' => ['7001']]]);

    return AdAccount::factory()->tiktok()->create(['external_id' => '7001', 'connection_id' => $c->id]);
}

function r3MetaAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'meta-secret']]);

    return AdAccount::factory()->meta()->create(['external_id' => 'act_1234', 'connection_id' => $c->id]);
}

it('Meta: posts the new daily budget in minor units to the ad set', function () {
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/v23.0/9200' => Http::response(['success' => true])]);

    app(MetaAdsWriter::class)->setDailyBudget(r3MetaAccount(), 'adset', '9200', 60000);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v23.0/9200') && (string) $r->data()['daily_budget'] === '60000');
});

it('Meta: refuses an ad (no budget of its own) and a zero budget before any call', function () {
    Http::preventStrayRequests();
    $w = app(MetaAdsWriter::class);
    $acc = r3MetaAccount();

    expect(fn () => $w->setDailyBudget($acc, 'ad', '9300', 60000))->toThrow(AdsApiException::class)
        ->and(fn () => $w->setDailyBudget($acc, 'adset', '9200', 0))->toThrow(AdsApiException::class);
    Http::assertNothingSent();
});

it('TikTok: ad group budget goes through adgroup/budget/update in the major unit', function () {
    Http::preventStrayRequests();
    Http::fake(['business-api.tiktok.com/open_api/v1.3/adgroup/budget/update/' => Http::response(['code' => 0, 'data' => []])]);

    app(TikTokAdsWriter::class)->setDailyBudget(r3TikTokAccount(), 'adset', '9200', 60000);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->hasHeader('Access-Token', 'tt-secret')
        && $r->data()['advertiser_id'] === '7001' && $r->data()['budgets'] === [['adgroup_id' => '9200', 'budget' => 600.0]]);
});

it('TikTok: a campaign budget goes through campaign/update, an ad is refused', function () {
    Http::preventStrayRequests();
    Http::fake(['business-api.tiktok.com/open_api/v1.3/campaign/update/' => Http::response(['code' => 0, 'data' => []])]);
    $acc = r3TikTokAccount();

    app(TikTokAdsWriter::class)->setDailyBudget($acc, 'campaign', '5001', 125050);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'campaign/update/') && $r->data()['campaign_id'] === '5001' && $r->data()['budget'] === 1250.5);

    expect(fn () => app(TikTokAdsWriter::class)->setDailyBudget($acc, 'ad', '1', 100))->toThrow(AdsApiException::class);
});
