<?php

use App\Ads\Control\StopAdvisor;
use App\Ads\Platforms\Meta\MetaAdsDriver;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\RunningCreatives;
use App\Ads\Sync\AdsSyncService;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdPlatformConnection;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Cairo'));
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'live', 'crm.ads.history_start' => '2026-09-01']);
});

function sweepAccount(): AdAccount
{
    $c = AdPlatformConnection::factory()->meta()->create(['credentials' => ['access_token' => 'tok']]);

    return AdAccount::factory()->meta()->create(['external_id' => 'act_1', 'connection_id' => $c->id]);
}

/**
 * Meta fake for a sync. $live = the full ad list (id => effective status), $archived = the ARCHIVED/DELETED list,
 * $campaigns = the campaigns list rows. Read by reference so a test can change Meta between two syncs.
 */
function fakeSweepMeta(array &$live, array &$archived, array &$campaigns): void
{
    Http::fake(function (Request $r) use (&$live, &$archived, &$campaigns) {
        $url = $r->url();
        if (str_contains($url, 'act_1/ads')) {
            $statuses = json_decode((string) ($r->data()['effective_status'] ?? '[]'), true);
            if (array_intersect($statuses, MetaAdsDriver::SWEEP_AD_STATUSES) !== []) {
                $hits = array_filter($archived, fn ($st) => in_array($st, $statuses, true));

                return Http::response(['data' => array_map(fn ($id, $st) => ['id' => $id, 'status' => $st, 'effective_status' => $st], array_keys($hits), $hits)]);
            }

            return Http::response(['data' => array_map(fn ($id, $st) => ['id' => $id, 'name' => $id, 'status' => $st, 'effective_status' => $st,
                'campaign' => ['id' => 'c_live', 'name' => 'Live campaign', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES']], array_keys($live), $live)]);
        }
        if (str_contains($url, 'act_1/campaigns')) {
            return Http::response(['data' => $campaigns]);
        }

        return Http::response(['data' => []]);
    });
}

function deepSync(AdAccount $acc)
{
    $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();

    return app(AdsSyncService::class)->syncAccount($acc, $today->subDays(29), $today, 'recent');
}

function statusRequests(): array
{
    return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(function (Request $r) {
        if (str_contains($r->url(), 'act_1/campaigns')) {
            return true;
        }

        $statuses = json_decode((string) ($r->data()['effective_status'] ?? '[]'), true) ?: [];

        return str_contains($r->url(), 'act_1/ads') && array_intersect($statuses, MetaAdsDriver::SWEEP_AD_STATUSES) !== [];
    })->values()->all();
}

it('asks meta for the archived/deleted ads (one call per status) and every campaign with light fields', function () {
    $live = [];
    $archived = [];
    $campaigns = [];
    fakeSweepMeta($live, $archived, $campaigns);

    $out = app(MetaAdsDriver::class)->statuses(sweepAccount());

    expect($out)->toBe(['ads' => [], 'campaigns' => [], 'warnings' => [], 'ads_complete' => true]);
    $requests = statusRequests();
    expect($requests)->toHaveCount(1 + count(MetaAdsDriver::SWEEP_AD_STATUSES));
    $ads = collect($requests)->filter(fn ($r) => str_contains($r->url(), 'act_1/ads'))->values();
    $camps = collect($requests)->first(fn ($r) => str_contains($r->url(), 'act_1/campaigns'));
    expect($ads->map(fn ($r) => json_decode($r['effective_status'], true))->all())
        ->toBe(array_map(fn ($s) => [$s], MetaAdsDriver::SWEEP_AD_STATUSES))
        ->and($ads[0]['fields'])->toBe('id,status,effective_status')
        ->and((int) $ads[0]['limit'])->toBe(500)
        ->and($camps['fields'])->toBe('id,name,status,effective_status,objective')
        ->and(json_decode($camps['effective_status'], true))->toBe(MetaAdsDriver::CAMPAIGN_STATUSES)
        ->and((int) $camps['limit'])->toBe(500);
});

it('a status meta refuses on /ads costs only that status and blocks GONE for the run', function () {
    $acc = sweepAccount();
    Http::fake(function (Request $r) {
        if (str_contains($r->url(), 'act_1/ads') && str_contains(urldecode($r->url()), '"DELETED"')) {
            return Http::response(['error' => ['code' => 100, 'message' => 'Invalid parameter']], 400);
        }
        if (str_contains($r->url(), 'act_1/ads') && str_contains(urldecode($r->url()), '"ARCHIVED"')) {
            return Http::response(['data' => [['id' => 'ad_x', 'status' => 'ARCHIVED', 'effective_status' => 'ARCHIVED']]]);
        }

        return Http::response(['data' => []]);
    });

    $out = app(MetaAdsDriver::class)->statuses($acc);

    expect($out['ads'])->toHaveKey('ad_x')
        ->and($out['ads_complete'])->toBeFalse()
        ->and(implode(' ', $out['warnings']))->toContain('ads list DELETED: ')
        ->and($out['campaigns'])->toBe([]);
});

it('marks an ad archived on meta after the deep sync', function () {
    $acc = sweepAccount();
    $ad = Ad::factory()->for($acc, 'account')->create(['external_id' => 'ad_x', 'name' => 'Archived loser', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    $live = [];
    $archived = ['ad_x' => 'ARCHIVED'];
    $campaigns = [];
    fakeSweepMeta($live, $archived, $campaigns);

    $run = deepSync($acc);

    $ad->refresh();
    expect($run->status)->toBe('ok')
        ->and($ad->effective_status)->toBe('ARCHIVED')
        ->and($ad->status)->toBe('ARCHIVED')
        ->and($ad->last_seen_at)->not->toBeNull();
});

it('never suggests stopping an ad whose effective status is archived, deleted or gone, even if its own status says active', function () {
    $acc = AdAccount::factory()->meta()->create();
    $kept = Ad::factory()->for($acc, 'account')->create(['name' => 'Live loser']);
    $gone = [];
    foreach (['ARCHIVED', 'DELETED', 'GONE'] as $st) {
        $gone[] = Ad::factory()->for($acc, 'account')->create(['name' => "Stale {$st}", 'status' => 'ACTIVE', 'effective_status' => $st]);
    }
    foreach (CarbonPeriod::create('2026-09-17', '2026-09-30') as $d) {
        foreach ([$kept, ...$gone] as $a) {
            AdDailyMetric::factory()->create(['ad_id' => $a->id, 'ad_account_id' => $acc->id, 'date' => $d->toDateString(),
                'spend' => 100, 'purchase_value' => 0, 'purchases' => 0, 'impressions' => 1000, 'clicks' => 20, 'reach' => 800]);
        }
    }

    $out = collect(app(StopAdvisor::class)->suggest(new AdsFilter(CarbonImmutable::parse('2026-09-17'), CarbonImmutable::parse('2026-09-30'))));

    expect($out->pluck('name')->all())->toBe(['Live loser']);
});

it('counts archived and gone ads as not running on the creatives page', function () {
    $acc = AdAccount::factory()->meta()->create();
    foreach (['ACTIVE', 'ARCHIVED', 'GONE'] as $st) {
        $a = Ad::factory()->for($acc, 'account')->create(['effective_status' => $st]);
        AdDailyMetric::factory()->create(['ad_id' => $a->id, 'ad_account_id' => $acc->id, 'date' => '2026-09-20', 'spend' => 50]);
    }

    $out = app(RunningCreatives::class)->build(new AdsFilter(CarbonImmutable::parse('2026-09-17'), CarbonImmutable::parse('2026-09-30')), ['status' => 'active']);

    expect($out['counts'])->toBe(['all' => 3, 'active' => 1, 'inactive' => 2])->and($out['data'])->toHaveCount(1);
});

it('marks an ad missing from every list twice as GONE, and leaves an ad missing once alone', function () {
    $acc = sweepAccount();
    $missingTwice = Ad::factory()->for($acc, 'account')->create(['external_id' => 'ad_lost', 'effective_status' => 'ACTIVE']);
    $missingOnce = Ad::factory()->for($acc, 'account')->create(['external_id' => 'ad_once', 'effective_status' => 'ACTIVE']);
    $live = ['ad_once' => 'ACTIVE', 'ad_ok' => 'ACTIVE'];
    $archived = [];
    $campaigns = [];
    fakeSweepMeta($live, $archived, $campaigns);
    $this->travel(1)->minutes(); // the stored ads exist before the first sweep starts (time is frozen in tests)

    deepSync($acc);
    expect($missingTwice->refresh()->effective_status)->toBe('ACTIVE'); // the first sweep has no earlier sweep to compare with

    $this->travel(1)->days();
    $live = ['ad_ok' => 'ACTIVE'];
    $run = deepSync($acc);

    expect($run->status)->toBe('ok')
        ->and($missingTwice->refresh()->effective_status)->toBe('GONE')
        ->and($missingOnce->refresh()->effective_status)->toBe('ACTIVE')
        ->and(Ad::where('external_id', 'ad_ok')->value('effective_status'))->toBe('ACTIVE');
});

it('takes campaign status from the campaigns list even when the campaign has no ads in the ad list', function () {
    $acc = sweepAccount();
    $camp = AdCampaign::factory()->create(['ad_account_id' => $acc->id, 'external_id' => 'c_paused', 'name' => 'Old name', 'status' => 'ACTIVE']);
    $live = [];
    $archived = [];
    $campaigns = [['id' => 'c_paused', 'name' => 'Ramadan', 'status' => 'PAUSED', 'effective_status' => 'PAUSED', 'objective' => 'OUTCOME_SALES']];
    fakeSweepMeta($live, $archived, $campaigns);

    deepSync($acc);

    $camp->refresh();
    expect($camp->status)->toBe('PAUSED')
        ->and($camp->effective_status)->toBe('PAUSED')
        ->and($camp->name)->toBe('Ramadan')
        ->and($camp->objective)->toBe('OUTCOME_SALES')
        ->and($camp->last_seen_at)->not->toBeNull();
});

it('makes no status-list requests on the hourly sync', function () {
    $acc = sweepAccount();
    $live = [];
    $archived = [];
    $campaigns = [];
    fakeSweepMeta($live, $archived, $campaigns);
    $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();

    $run = app(AdsSyncService::class)->syncAccount($acc, $today->subDays(2), $today, 'recent');

    expect($run->status)->toBe('ok')->and(statusRequests())->toBe([]);
});

it('sweeps once in a backfill, on its first chunk', function () {
    $acc = sweepAccount();
    $live = [];
    $archived = [];
    $campaigns = [];
    fakeSweepMeta($live, $archived, $campaigns);

    app(AdsSyncService::class)->backfill($acc, 35);

    expect(collect(statusRequests())->filter(fn ($r) => str_contains($r->url(), 'act_1/campaigns')))->toHaveCount(1);
});

it('covers every insights status between the full ad list and the sweep list', function () {
    expect(array_values(array_unique([...MetaAdsDriver::AD_STATUSES, ...MetaAdsDriver::SWEEP_AD_STATUSES])))
        ->toEqualCanonicalizing(MetaAdsDriver::INSIGHTS_STATUSES);
});

it('never marks a PENDING_BILLING_INFO ad GONE: the sweep list reports it', function () {
    $acc = sweepAccount();
    $billing = Ad::factory()->for($acc, 'account')->create(['external_id' => 'ad_bill', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    $live = [];
    $archived = ['ad_bill' => 'PENDING_BILLING_INFO'];
    $campaigns = [];
    fakeSweepMeta($live, $archived, $campaigns);
    $this->travel(1)->minutes();

    deepSync($acc);
    $this->travel(1)->days();
    deepSync($acc);

    expect($billing->refresh()->effective_status)->toBe('PENDING_BILLING_INFO');
});

it('keeps the ads list and a completed sweep when only the campaigns list fails', function () {
    $acc = sweepAccount();
    $ad = Ad::factory()->for($acc, 'account')->create(['external_id' => 'ad_x', 'effective_status' => 'ACTIVE']);
    Http::fake(function (Request $r) {
        if (str_contains($r->url(), 'act_1/campaigns')) {
            return Http::response(['error' => ['message' => 'Unsupported get request', 'code' => 100]], 400);
        }
        if (str_contains($r->url(), 'act_1/ads') && str_contains((string) ($r->data()['effective_status'] ?? ''), 'ARCHIVED')) {
            return Http::response(['data' => [['id' => 'ad_x', 'status' => 'ARCHIVED', 'effective_status' => 'ARCHIVED']]]);
        }

        return Http::response(['data' => []]);
    });

    $run = deepSync($acc);

    expect($run->status)->toBe('ok')->and($run->error)->toContain('Status sweep')->toContain('campaigns list')->toContain('Unsupported get request')
        ->and($run->swept_at)->not->toBeNull()
        ->and($ad->refresh()->effective_status)->toBe('ARCHIVED');
});

it('marks nothing GONE and records no completed sweep when the ads sweep list fails', function () {
    $acc = sweepAccount();
    $lost = Ad::factory()->for($acc, 'account')->create(['external_id' => 'ad_lost', 'effective_status' => 'ACTIVE']);
    $failAds = false;
    Http::fake(function (Request $r) use (&$failAds) {
        if ($failAds && str_contains($r->url(), 'act_1/ads') && str_contains((string) ($r->data()['effective_status'] ?? ''), 'ARCHIVED')) {
            return Http::response(['error' => ['message' => 'Unsupported get request', 'code' => 100]], 400);
        }

        return Http::response(['data' => []]);
    });
    $this->travel(1)->minutes();
    deepSync($acc); // a completed sweep

    $this->travel(1)->days();
    $failAds = true;
    $run = deepSync($acc);

    expect($run->status)->toBe('ok')->and($run->error)->toContain('ads list')
        ->and($run->swept_at)->toBeNull()
        ->and($lost->refresh()->effective_status)->toBe('ACTIVE');
});

it('updates the archived ads with one query per status pair', function () {
    $acc = sweepAccount();
    foreach (['a1', 'a2', 'a3', 'd1'] as $id) {
        Ad::factory()->for($acc, 'account')->create(['external_id' => $id, 'effective_status' => 'ACTIVE']);
    }
    $live = [];
    $archived = ['a1' => 'ARCHIVED', 'a2' => 'ARCHIVED', 'a3' => 'ARCHIVED', 'd1' => 'DELETED'];
    $campaigns = [];
    fakeSweepMeta($live, $archived, $campaigns);
    $updates = 0;
    DB::listen(function ($q) use (&$updates) {
        if (str_starts_with(strtolower($q->sql), 'update "ads"') && str_contains($q->sql, '"last_seen_at"') && str_contains($q->sql, '"effective_status"')) {
            $updates++;
        }
    });

    deepSync($acc);

    expect($updates)->toBe(2)
        ->and(Ad::where('ad_account_id', $acc->id)->where('effective_status', 'ARCHIVED')->count())->toBe(3)
        ->and(Ad::where('external_id', 'd1')->value('effective_status'))->toBe('DELETED');
});
