<?php

use App\Ads\Platforms\RateLimited;
use App\Ads\Platforms\TikTok\TikTokAdsDriver;
use App\Ads\Sync\AdsSyncService;
use App\Models\AdAccount;
use App\Models\AdAccountDaily;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use App\Models\AdsSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'live', 'crm.ads.drivers.tiktok' => 'live']);
});

function controlAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'tok']]);

    return AdAccount::factory()->meta()->create(['external_id' => 'act_1', 'connection_id' => $c->id]);
}

/** One account-level row per day, as Meta answers level=account&time_increment=1. */
function accountRow(string $date, string $spend, string $purchases = '0', string $value = '0'): array
{
    return [
        'date_start' => $date, 'date_stop' => $date, 'spend' => $spend, 'impressions' => '5000', 'account_currency' => 'EGP',
        'actions' => [['action_type' => 'omni_purchase', 'value' => '99'], ['action_type' => 'purchase', 'value' => $purchases]],
        'action_values' => [['action_type' => 'purchase', 'value' => $value]],
    ];
}

function insightAdRow(string $ad, string $date, string $spend): array
{
    return ['ad_id' => $ad, 'ad_name' => $ad, 'spend' => $spend, 'impressions' => '100', 'clicks' => '5', 'reach' => '90', 'date_start' => $date, 'date_stop' => $date];
}

/**
 * Meta fake: the ad list is empty, ad-level insights answer $adRows, account-level insights answer $accountRows
 * (or $accountResponse when given), anything else (creative media) answers an empty list.
 */
function fakeMetaWithControl(array $adRows, array $accountRows, $accountResponse = null): void
{
    Http::fake(function (Request $r) use ($adRows, $accountRows, $accountResponse) {
        if (str_contains($r->url(), 'act_1/insights')) {
            if (($r->data()['level'] ?? null) === 'account') {
                return $accountResponse ?? Http::response(['data' => $accountRows]);
            }

            return Http::response(['data' => $adRows]);
        }

        return Http::response(['data' => []]);
    });
}

/** @return list<Request> */
function insightRequests(string $level): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_contains($r->url(), 'act_1/insights') && ($r->data()['level'] ?? null) === $level)
        ->values()->all();
}

it('makes one level=account request for the run window with the same time range and attribution', function () {
    fakeMetaWithControl([insightAdRow('ad_1', '2026-09-01', '100')], [
        accountRow('2026-09-01', '100', '2', '900'), accountRow('2026-09-02', '50', '1', '450'), accountRow('2026-09-03', '0'),
    ]);
    $acc = controlAccount();

    $run = app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-03'));

    $requests = insightRequests('account');
    expect($run->status)->toBe('ok')->and($requests)->toHaveCount(1);
    $r = $requests[0];
    expect((string) $r['time_increment'])->toBe('1')
        ->and($r['time_range'])->toBe(insightRequests('ad')[0]['time_range'])
        ->and(json_decode($r['time_range'], true))->toBe(['since' => '2026-09-01', 'until' => '2026-09-03'])
        ->and($r['use_unified_attribution_setting'])->toBe('true')
        ->and($r['action_report_time'])->toBe('impression')
        ->and(explode(',', $r['fields']))->toEqualCanonicalizing(['spend', 'impressions', 'actions', 'action_values', 'account_currency'])
        ->and(isset($r->data()['filtering']))->toBeFalse();

    $day = AdAccountDaily::where('ad_account_id', $acc->id)->where('date', '2026-09-01')->firstOrFail();
    expect(AdAccountDaily::where('ad_account_id', $acc->id)->count())->toBe(3)
        ->and((float) $day->spend)->toBe(100.0)
        ->and((int) $day->impressions)->toBe(5000)
        ->and((float) $day->purchases)->toBe(2.0)
        ->and((float) $day->purchase_value)->toBe(900.0)
        ->and($day->currency)->toBe('EGP')
        ->and((float) $day->first_spend)->toBe(100.0)
        ->and((float) $day->first_purchases)->toBe(2.0)
        ->and((float) $day->first_purchase_value)->toBe(900.0)
        ->and($day->first_fetched_at)->not->toBeNull()
        ->and($day->fetched_at)->not->toBeNull();
});

it('updates the latest values on a restatement and keeps the first ones', function () {
    $acc = controlAccount();
    // Http::fake stubs stack (the first match wins), so one stub reads the current answer by reference.
    $answer = ['data' => [accountRow('2026-09-01', '100', '2', '900')]];
    Http::fake(function (Request $r) use (&$answer) {
        if (str_contains($r->url(), 'act_1/insights')) {
            return Http::response(($r->data()['level'] ?? null) === 'account' ? $answer : ['data' => [insightAdRow('ad_1', '2026-09-01', '100')]]);
        }

        return Http::response(['data' => []]);
    });
    $this->travelTo(CarbonImmutable::parse('2026-09-02 08:00:00', 'UTC'));
    app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-01'));

    $this->travelTo(CarbonImmutable::parse('2026-09-03 08:00:00', 'UTC'));
    $answer = ['data' => [accountRow('2026-09-01', '101', '3', '1350')]];
    app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-01'));

    $day = AdAccountDaily::where('ad_account_id', $acc->id)->sole();
    expect((float) $day->purchase_value)->toBe(1350.0)
        ->and((float) $day->purchases)->toBe(3.0)
        ->and((float) $day->spend)->toBe(101.0)
        ->and((float) $day->first_purchase_value)->toBe(900.0)
        ->and((float) $day->first_purchases)->toBe(2.0)
        ->and((float) $day->first_spend)->toBe(100.0)
        ->and($day->first_fetched_at->toDateTimeString())->toBe('2026-09-02 08:00:00')
        ->and($day->fetched_at->toDateTimeString())->toBe('2026-09-03 08:00:00');
});

it('keeps the run ok with a warning when the control request fails', function () {
    fakeMetaWithControl([insightAdRow('ad_1', '2026-09-01', '100')], [], Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400));
    $acc = controlAccount();

    $run = app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-01'));

    expect($run->status)->toBe('ok')
        ->and($run->error)->toContain('Account totals')->toContain('Invalid parameter')
        ->and(AdDailyMetric::where('ad_account_id', $acc->id)->count())->toBe(1)
        ->and(AdAccountDaily::count())->toBe(0);
});

it('lets a rate limit on the control request stop the run', function () {
    fakeMetaWithControl([insightAdRow('ad_1', '2026-09-01', '100')], [], Http::response(['error' => ['message' => 'too many calls', 'code' => 80004]], 400));
    $acc = controlAccount();

    expect(fn () => app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-01')))
        ->toThrow(RateLimited::class);
    expect(AdsSyncRun::latest('id')->first()->status)->toBe('error')
        ->and(AdAccountDaily::count())->toBe(0);
});

it('never stores a control row before the history start', function () {
    config(['crm.ads.history_start' => '2026-09-02']);
    fakeMetaWithControl([], [accountRow('2026-09-01', '10'), accountRow('2026-09-02', '20')]);
    $acc = controlAccount();

    app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-02'), CarbonImmutable::parse('2026-09-02'));

    expect(AdAccountDaily::pluck('date')->map(fn ($d) => $d->toDateString())->all())->toBe(['2026-09-02']);
});

it('asks tiktok for no control and stores no rows', function () {
    Http::fake([
        'business-api.tiktok.com/open_api/v1.3/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['list' => [], 'page_info' => ['page' => 1, 'total_page' => 1]]]),
    ]);
    $conn = AdPlatformConnection::factory()->tiktok()->create(['credentials' => ['access_token' => 'TT', 'advertiser_ids' => ['7000000000001']]]);
    $acc = AdAccount::factory()->tiktok()->create(['external_id' => '7000000000001', 'connection_id' => $conn->id]);

    expect(app(TikTokAdsDriver::class)->accountDaily($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-03')))->toBeNull();
    Http::assertNothingSent();

    $run = app(AdsSyncService::class)->syncAccount($acc, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-03'));

    expect($run->status)->toBe('ok')->and(AdAccountDaily::count())->toBe(0);
    $reports = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), 'report/integrated/get'));
    expect($reports)->toHaveCount(1)->and($reports->first()->data()['data_level'] ?? null)->toBe('AUCTION_AD');
});
