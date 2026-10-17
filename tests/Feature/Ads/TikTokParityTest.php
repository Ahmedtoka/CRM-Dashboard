<?php

use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\TikTok\TikTokAdsDriver;
use App\Ads\Sync\AdsSyncService;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountDaily;
use App\Models\AdCampaign;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const TTP = 'business-api.tiktok.com/open_api/v1.3/';

function ttpAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->tiktok()->create(['credentials' => ['access_token' => 'TTSECRET', 'advertiser_ids' => ['7001']]]);

    return AdAccount::factory()->tiktok()->create(['external_id' => '7001', 'connection_id' => $c->id, 'currency' => 'EGP']);
}

function ttpList(array $rows): array
{
    return ['code' => 0, 'message' => 'OK', 'data' => ['list' => $rows, 'page_info' => ['page' => 1, 'total_page' => 1]]];
}

function ttpFilter(Request $r): array
{
    parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

    return json_decode((string) ($q['filtering'] ?? '{}'), true) ?: [];
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.ads.drivers.tiktok' => 'live', 'crm.ads.history_start' => '2026-09-01']);
});

it('fills campaign status, objective, ad group status and the headline on the ad list', function () {
    Http::fake([
        TTP.'ad/get/*' => Http::response(ttpList([['ad_id' => '1801', 'ad_name' => 'V1', 'operation_status' => 'ENABLE', 'secondary_status' => 'AD_STATUS_DELIVERY_OK',
            'campaign_id' => '501', 'campaign_name' => 'Sales', 'adgroup_id' => '9001', 'adgroup_name' => 'Women', 'display_name' => 'Le Voile', 'ad_text' => 'Hi', 'video_id' => 'v1']])),
        TTP.'campaign/get/*' => Http::response(ttpList([['campaign_id' => '501', 'operation_status' => 'ENABLE', 'secondary_status' => 'CAMPAIGN_STATUS_ENABLE', 'objective_type' => 'WEB_CONVERSIONS']])),
        TTP.'adgroup/get/*' => Http::response(ttpList([['adgroup_id' => '9001', 'operation_status' => 'DISABLE', 'secondary_status' => 'ADGROUP_STATUS_DISABLE']])),
    ]);

    $ads = app(TikTokAdsDriver::class)->ads(ttpAccount());

    expect($ads[0]->campaignStatus)->toBe('ENABLE')->and($ads[0]->objective)->toBe('WEB_CONVERSIONS')
        ->and($ads[0]->adSetStatus)->toBe('DISABLE')->and($ads[0]->headline)->toBe('Le Voile')
        // Delivering is the Ads Hub's ACTIVE (screens count effective_status = ACTIVE); the own status keeps TikTok's spelling.
        ->and($ads[0]->effectiveStatus)->toBe('ACTIVE')->and($ads[0]->status)->toBe('ENABLE');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'campaign/get/') && ttpFilter($r)['campaign_ids'] === ['501']);
});

it('keeps the ad list when the campaign or ad group lookup fails, with a warning', function () {
    Http::fake([
        TTP.'ad/get/*' => Http::response(ttpList([['ad_id' => '1801', 'ad_name' => 'V1', 'operation_status' => 'ENABLE', 'campaign_id' => '501', 'adgroup_id' => '9001']])),
        TTP.'campaign/get/*' => Http::response(['code' => 40002, 'message' => 'scope not granted', 'data' => []]),
        TTP.'adgroup/get/*' => Http::response(['code' => 50000, 'message' => 'boom', 'data' => []]),
    ]);
    $driver = app(TikTokAdsDriver::class);

    $ads = $driver->ads(ttpAccount());

    expect($ads)->toHaveCount(1)->and($ads[0]->campaignStatus)->toBeNull()->and($driver->drainWarnings())->toHaveCount(2);
});

it('stops the ad list on a rate limit instead of hiding it in a warning', function () {
    Http::fake([
        TTP.'ad/get/*' => Http::response(ttpList([['ad_id' => '1801', 'campaign_id' => '501', 'adgroup_id' => '9001']])),
        TTP.'*' => Http::response(['code' => 40100, 'message' => 'Too many requests', 'data' => []]),
    ]);

    expect(fn () => app(TikTokAdsDriver::class)->ads(ttpAccount()))->toThrow(RateLimited::class);
});

it('lists the deleted ads and every campaign for the nightly sweep', function () {
    Http::fake([
        TTP.'ad/get/*' => Http::response(ttpList([['ad_id' => '1809', 'operation_status' => 'DISABLE', 'secondary_status' => 'AD_STATUS_DELETE']])),
        TTP.'campaign/get/*' => Http::response(ttpList([['campaign_id' => '501', 'campaign_name' => 'Sales', 'operation_status' => 'ENABLE', 'secondary_status' => 'CAMPAIGN_STATUS_ENABLE', 'objective_type' => 'WEB_CONVERSIONS']])),
    ]);

    $lists = app(TikTokAdsDriver::class)->statuses(ttpAccount());

    expect($lists['ads'])->toBe(['1809' => ['status' => 'DISABLE', 'effective_status' => 'DELETED']])
        ->and($lists['ads_complete'])->toBeTrue()
        ->and($lists['campaigns'])->toBe(['501' => ['name' => 'Sales', 'status' => 'ENABLE', 'effective_status' => 'ACTIVE', 'objective' => 'WEB_CONVERSIONS']]);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/ad/get/') && ttpFilter($r) === ['primary_status' => 'STATUS_DELETE']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'campaign/get/') && ttpFilter($r) === ['primary_status' => 'STATUS_ALL']);
});

it('keeps the campaigns list when the deleted-ads list fails (no GONE from it)', function () {
    Http::fake([
        TTP.'ad/get/*' => Http::response(['code' => 50000, 'message' => 'boom', 'data' => []]),
        TTP.'campaign/get/*' => Http::response(ttpList([['campaign_id' => '501', 'operation_status' => 'DISABLE']])),
    ]);

    $lists = app(TikTokAdsDriver::class)->statuses(ttpAccount());

    expect($lists['ads'])->toBeNull()->and($lists['campaigns'])->toHaveKey('501')->and($lists['warnings'])->toHaveCount(1);
});

it('reads the light campaign statuses for the hourly run', function () {
    Http::fake([TTP.'campaign/get/*' => Http::response(ttpList([
        ['campaign_id' => '501', 'operation_status' => 'ENABLE', 'secondary_status' => 'CAMPAIGN_STATUS_ENABLE'],
        ['campaign_id' => '502', 'operation_status' => 'DISABLE', 'secondary_status' => 'CAMPAIGN_STATUS_DISABLE'],
    ]))]);

    expect(app(TikTokAdsDriver::class)->campaignStatuses(ttpAccount()))->toBe([
        '501' => ['status' => 'ENABLE', 'effective_status' => 'ACTIVE'],
        '502' => ['status' => 'DISABLE', 'effective_status' => 'CAMPAIGN_STATUS_DISABLE'],
    ]);
    Http::assertSent(fn (Request $r) => ttpFilter($r) === ['primary_status' => 'STATUS_NOT_DELETE']);
});

it('reads account-level daily totals as the control', function () {
    Http::fake([TTP.'report/integrated/get/*' => Http::response(ttpList([
        ['dimensions' => ['advertiser_id' => '7001', 'stat_time_day' => '2026-09-01 00:00:00'], 'metrics' => ['spend' => '120.50', 'impressions' => '9000', 'complete_payment' => '3', 'total_complete_payment_rate' => '1500.00', 'currency' => 'EGP']],
        ['dimensions' => ['advertiser_id' => '7001', 'stat_time_day' => '2026-09-02 00:00:00'], 'metrics' => ['spend' => '0', 'impressions' => '0']],
    ]))]);

    $rows = app(TikTokAdsDriver::class)->accountDaily(ttpAccount(), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->date)->toBe('2026-09-01')->and($rows[0]->spend)->toBe(120.5)->and($rows[0]->impressions)->toBe(9000)
        ->and($rows[0]->purchases)->toBe(3.0)->and($rows[0]->purchaseValue)->toBe(1500.0)->and($rows[0]->currency)->toBe('EGP')
        ->and($rows[1]->spend)->toBe(0.0);
    Http::assertSent(function (Request $r) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return $q['data_level'] === 'AUCTION_ADVERTISER' && json_decode($q['dimensions'], true) === ['advertiser_id', 'stat_time_day']
            && $q['start_date'] === '2026-09-01' && $q['end_date'] === '2026-09-02';
    });
});

it('fetches image ad pictures as well as video covers', function () {
    $acc = ttpAccount();
    Ad::factory()->for($acc, 'account')->create(['external_id' => '1802', 'raw' => ['image_ids' => ['img_1']]]);
    Http::fake([TTP.'file/image/ad/info/*' => Http::response(['code' => 0, 'data' => ['list' => [['image_id' => 'img_1', 'image_url' => 'https://p16.tiktokcdn.test/img_1.jpg']]]])]);

    $media = app(TikTokAdsDriver::class)->creativeMedia($acc, ['1802']);

    expect($media[0]->imageUrl)->toBe('https://p16.tiktokcdn.test/img_1.jpg')->and($media[0]->thumbnailUrl)->toBe('https://p16.tiktokcdn.test/img_1.jpg');
});

it('a nightly TikTok sync stores campaign statuses, the control totals and marks ads GONE after two sweeps', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00', 'Africa/Cairo'));
    $acc = ttpAccount();
    $gone = Ad::factory()->for($acc, 'account')->create(['external_id' => '1899', 'effective_status' => 'ACTIVE', 'last_seen_at' => now()->subDays(3)]);
    Http::fake(function (Request $r) {
        $url = $r->url();
        $f = ttpFilter($r);

        return Http::response(match (true) {
            str_contains($url, '/ad/get/') && ($f['primary_status'] ?? null) === 'STATUS_DELETE' => ttpList([]),
            str_contains($url, '/ad/get/') => ttpList([['ad_id' => '1801', 'ad_name' => 'V1', 'operation_status' => 'ENABLE', 'secondary_status' => 'AD_STATUS_DELIVERY_OK', 'campaign_id' => '501', 'campaign_name' => 'Sales', 'adgroup_id' => '9001', 'adgroup_name' => 'W']]),
            str_contains($url, 'campaign/get/') => ttpList([['campaign_id' => '501', 'campaign_name' => 'Sales', 'operation_status' => 'ENABLE', 'secondary_status' => 'CAMPAIGN_STATUS_ENABLE', 'objective_type' => 'WEB_CONVERSIONS']]),
            str_contains($url, 'adgroup/get/') => ttpList([['adgroup_id' => '9001', 'operation_status' => 'ENABLE']]),
            str_contains($url, 'report/integrated/get/') && str_contains($url, 'AUCTION_ADVERTISER') => ttpList([['dimensions' => ['stat_time_day' => '2026-09-10 00:00:00'], 'metrics' => ['spend' => '10']]]),
            default => ttpList([]),
        });
    });

    $sync = app(AdsSyncService::class);
    $sync->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), 'recent', true); // a deep (30-day) run sweeps
    $this->travel(1)->days();
    $sync->syncAccount($acc, CarbonImmutable::parse('2026-09-02'), CarbonImmutable::parse('2026-10-01'), 'recent', true);

    expect(AdCampaign::where('ad_account_id', $acc->id)->where('external_id', '501')->value('status'))->toBe('ENABLE')
        ->and(AdAccountDaily::where('ad_account_id', $acc->id)->where('date', '2026-09-10')->value('spend'))->toEqual(10)
        ->and($gone->fresh()->effective_status)->toBe('GONE')
        ->and(Ad::where('external_id', '1801')->value('effective_status'))->toBe('ACTIVE');
});
