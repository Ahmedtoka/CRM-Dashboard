<?php

use App\Ads\Platforms\AdsApiException;
use App\Ads\Platforms\TikTok\TikTokAdsWriter;
use App\Models\AdAccount;
use App\Models\AdPlatformConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const TTR = 'business-api.tiktok.com/open_api/v1.3/';

function ttReadAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->tiktok()->create(['credentials' => ['access_token' => 'tt-secret', 'advertiser_ids' => ['7001']]]);

    return AdAccount::factory()->tiktok()->create(['external_id' => '7001', 'connection_id' => $c->id, 'currency' => 'EGP']);
}

function ttList(array $rows): array
{
    return ['code' => 0, 'message' => 'OK', 'data' => ['list' => $rows, 'page_info' => ['page' => 1, 'total_page' => 1]]];
}

/** Documented v1.3 shapes (ad/get, adgroup/get, campaign/get) with the fields readObject asks for. */
function ttFakeTree(array $ad = [], array $group = [], array $campaign = []): void
{
    Http::preventStrayRequests();
    Http::fake([
        TTR.'ad/get/*' => Http::response(ttList([$ad + ['ad_id' => '4401', 'operation_status' => 'DISABLE', 'secondary_status' => 'AD_STATUS_DISABLE', 'adgroup_id' => '9001', 'campaign_id' => '501']])),
        TTR.'adgroup/get/*' => Http::response(ttList([$group + ['adgroup_id' => '9001', 'campaign_id' => '501', 'operation_status' => 'ENABLE', 'secondary_status' => 'ADGROUP_STATUS_DELIVERY_OK',
            'budget' => 250.5, 'budget_mode' => 'BUDGET_MODE_DAY', 'schedule_type' => 'SCHEDULE_FROM_NOW', 'schedule_end_time' => '2036-10-17 00:00:00']])),
        TTR.'campaign/get/*' => Http::response(ttList([$campaign + ['campaign_id' => '501', 'operation_status' => 'ENABLE', 'secondary_status' => 'CAMPAIGN_STATUS_ENABLE',
            'budget' => 0, 'budget_mode' => 'BUDGET_MODE_INFINITE']])),
    ]);
}

it('reads an ad with its ad group and campaign, budgets in minor units, statuses in the shared spelling', function () {
    ttFakeTree();

    $s = app(TikTokAdsWriter::class)->readObject(ttReadAccount(), 'ad', '4401');

    expect($s->status)->toBe('PAUSED')->and($s->effectiveStatus)->toBe('AD_STATUS_DISABLE')
        ->and($s->dailyBudgetMinor)->toBeNull()->and($s->lifetimeBudgetMinor)->toBeNull()->and($s->currency)->toBe('EGP')
        ->and($s->parents)->toHaveCount(2)
        ->and($s->parents[0])->toMatchArray(['level' => 'adset', 'status' => 'ACTIVE', 'dailyBudgetMinor' => 25050, 'lifetimeBudgetMinor' => null, 'endsAt' => null])
        ->and($s->parents[1])->toMatchArray(['level' => 'campaign', 'status' => 'ACTIVE', 'dailyBudgetMinor' => null, 'lifetimeBudgetMinor' => null]);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ad/get/') && $r->hasHeader('Access-Token', 'tt-secret')
        && str_contains(urldecode($r->url()), '"ad_ids":["4401"]') && str_contains(urldecode($r->url()), 'advertiser_id=7001'));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'adgroup/get/') && str_contains(urldecode($r->url()), '"adgroup_ids":["9001"]'));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'campaign/get/') && str_contains(urldecode($r->url()), '"campaign_ids":["501"]'));
});

it('maps a total budget with an end date as lifetime and a dynamic daily campaign budget as daily', function () {
    ttFakeTree(
        group: ['budget' => '3000.00', 'budget_mode' => 'BUDGET_MODE_TOTAL', 'schedule_type' => 'SCHEDULE_START_END', 'schedule_end_time' => '2026-10-30 21:59:59'],
        campaign: ['budget' => 400, 'budget_mode' => 'BUDGET_MODE_DYNAMIC_DAILY_BUDGET'],
    );

    $s = app(TikTokAdsWriter::class)->readObject(ttReadAccount(), 'adset', '9001');

    expect($s->status)->toBe('ACTIVE')->and($s->lifetimeBudgetMinor)->toBe(300000)->and($s->dailyBudgetMinor)->toBeNull()
        ->and($s->endsAt?->toIso8601String())->toBe('2026-10-30T21:59:59+00:00')
        ->and($s->parents)->toHaveCount(1)
        ->and($s->parents[0])->toMatchArray(['level' => 'campaign', 'dailyBudgetMinor' => 40000, 'lifetimeBudgetMinor' => null]);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/ad/get/'));
});

it('reads a campaign alone', function () {
    ttFakeTree(campaign: ['operation_status' => 'DISABLE', 'budget' => 150, 'budget_mode' => 'BUDGET_MODE_DAY']);

    $s = app(TikTokAdsWriter::class)->readObject(ttReadAccount(), 'campaign', '501');

    expect($s->status)->toBe('PAUSED')->and($s->dailyBudgetMinor)->toBe(15000)->and($s->parents)->toBe([]);
    Http::assertSentCount(1);
});

it('reports a deleted object as DELETED so it is never run', function () {
    ttFakeTree(ad: ['secondary_status' => 'AD_STATUS_DELETE']);

    expect(app(TikTokAdsWriter::class)->readObject(ttReadAccount(), 'ad', '4401')->status)->toBe('DELETED');
});

it('fails when TikTok does not return the object', function () {
    Http::preventStrayRequests();
    Http::fake([TTR.'ad/get/*' => Http::response(ttList([]))]);

    expect(fn () => app(TikTokAdsWriter::class)->readObject(ttReadAccount(), 'ad', '4401'))->toThrow(AdsApiException::class, 'TikTok did not return');
});

it('refuses non-numeric ids before any call', function () {
    Http::preventStrayRequests();

    expect(fn () => app(TikTokAdsWriter::class)->readObject(ttReadAccount(), 'ad', '44&01'))->toThrow(AdsApiException::class, 'Invalid TikTok id');
});
