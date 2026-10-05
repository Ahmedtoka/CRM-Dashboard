<?php

use App\Ads\Buyers\AssignmentService;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Ads\Reports\AdsQuery;
use App\Ads\Reports\RevenueSummary;
use App\Ads\Reports\TopAccounts;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountDaily;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\MediaBuyer;
use Carbon\CarbonImmutable;

function totFilter(array $extra = []): AdsFilter
{
    return new AdsFilter(CarbonImmutable::parse('2026-09-10'), CarbonImmutable::parse('2026-09-11'), ...$extra);
}

function totControl(AdAccount $acc, string $date, float $spend, float $value = 0): void
{
    AdAccountDaily::create([
        'ad_account_id' => $acc->id, 'date' => $date, 'spend' => $spend, 'purchase_value' => $value,
        'purchases' => 0, 'impressions' => 0, 'fetched_at' => now(),
    ]);
}

function totAdRow(Ad $ad, string $date, float $spend, float $value = 0): void
{
    AdDailyMetric::factory()->create([
        'ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => $date, 'spend' => $spend,
        'purchase_value' => $value, 'purchases' => 0, 'impressions' => 1000, 'clicks' => 10, 'reach' => 800,
    ]);
}

/** An ad of a PAUSED campaign (ads of paused campaigns still count in totals). */
function totAd(AdAccount $acc): Ad
{
    $camp = AdCampaign::factory()->create(['ad_account_id' => $acc->id, 'status' => 'PAUSED']);

    return Ad::factory()->for($acc, 'account')->create(['ad_campaign_id' => $camp->id]);
}

it('takes the totals from the account control and reports the residual not itemised by ad', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = totAd($acc);
    totAdRow($ad, '2026-09-10', 495, 1000);
    totAdRow($ad, '2026-09-11', 495, 1000);
    totControl($acc, '2026-09-10', 500, 1100);
    totControl($acc, '2026-09-11', 500, 1100);

    $t = app(AdsOverview::class)->build(totFilter())['totals'];

    expect($t['source'])->toBe('account')->and($t['spend'])->toBe(1000.0)->and($t['itemised_gap'])->toBe(10.0)
        ->and($t['purchase_value'])->toBe(2200.0)->and($t['roas'])->toBe(2.2)
        ->and($t['ctr'])->toBe(0.01)->and($t['cpm'])->toBe(495.0); // click-side figures keep the ad-level sums
});

it('uses the ad-level sum for a day without a control row and marks the total mixed', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = totAd($acc);
    totAdRow($ad, '2026-09-10', 495);
    totAdRow($ad, '2026-09-11', 495);
    totControl($acc, '2026-09-10', 500);

    $t = app(AdsOverview::class)->build(totFilter())['totals'];

    // day 10 from the control (500), day 11 from the ads (495); the gap covers day 10 only
    expect($t['source'])->toBe('mixed')->and($t['spend'])->toBe(995.0)->and($t['itemised_gap'])->toBe(5.0);
});

it('uses the control for meta and the ads for tiktok in one filter', function () {
    $meta = AdAccount::factory()->meta()->create();
    $tt = AdAccount::factory()->tiktok()->create();
    totAdRow(totAd($meta), '2026-09-10', 495);
    totControl($meta, '2026-09-10', 500);
    totAdRow(Ad::factory()->for($tt, 'account')->create(), '2026-09-10', 80);

    $t = app(AdsOverview::class)->build(totFilter())['totals'];

    expect($t['source'])->toBe('mixed')->and($t['spend'])->toBe(580.0)->and($t['itemised_gap'])->toBe(5.0);
});

it('counts a control day that has no ad rows at all', function () {
    $acc = AdAccount::factory()->meta()->create();
    totControl($acc, '2026-09-10', 300, 600);

    $t = app(AdsOverview::class)->build(totFilter())['totals'];

    expect($t['source'])->toBe('account')->and($t['spend'])->toBe(300.0)->and($t['itemised_gap'])->toBe(300.0)->and($t['roas'])->toBe(2.0);
});

it('flags a negative gap as updating and hides a gap within the tolerance', function () {
    $acc = AdAccount::factory()->meta()->create();
    totAdRow(totAd($acc), '2026-09-10', 1000);
    totControl($acc, '2026-09-10', 900);
    $t = app(AdsOverview::class)->build(totFilter())['totals'];
    expect($t['itemised_gap'])->toBe(-100.0)->and($t['gap_state'])->toBe('updating');

    AdAccountDaily::query()->update(['spend' => 1003]); // 0.3 percent, under the 0.5 percent tolerance
    app()->forgetInstance(AdsQuery::class);
    $t = app(AdsOverview::class)->build(totFilter())['totals'];
    expect($t['gap_state'])->toBe('none');

    AdAccountDaily::query()->update(['spend' => 1100]);
    app()->forgetInstance(AdsQuery::class);
    expect(app(AdsOverview::class)->build(totFilter())['totals']['gap_state'])->toBe('unitemised');
});

it('keeps spend outside active campaigns separate from the itemisation gap', function () {
    $acc = AdAccount::factory()->meta()->create();
    totAdRow(totAd($acc), '2026-09-10', 100); // PAUSED campaign
    totControl($acc, '2026-09-10', 130);

    $t = app(AdsOverview::class)->build(totFilter())['totals'];

    expect($t['spend'])->toBe(130.0)->and($t['spend_outside_active'])->toBe(100.0)->and($t['itemised_gap'])->toBe(30.0);
});

it('limits the control to the buyer account-days when a buyer is filtered', function () {
    $a = AdAccount::factory()->meta()->create();
    $b = AdAccount::factory()->meta()->create();
    $buyer = MediaBuyer::factory()->create();
    app(AssignmentService::class)->assign($a, $buyer, CarbonImmutable::parse('2026-09-01'));
    foreach ([$a, $b] as $acc) {
        $ad = totAd($acc);
        totAdRow($ad, '2026-09-10', 100);
        totAdRow($ad, '2026-09-11', 100);
        totControl($acc, '2026-09-10', 105);
        totControl($acc, '2026-09-11', 105);
    }

    $t = app(AdsOverview::class)->build(totFilter(['buyerId' => $buyer->id]))['totals'];

    expect($t['source'])->toBe('account')->and($t['spend'])->toBe(210.0)->and($t['itemised_gap'])->toBe(10.0);
});

it('uses the sum of ads for tiktok, which has no control rows', function () {
    $acc = AdAccount::factory()->tiktok()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    totAdRow($ad, '2026-09-10', 80);

    $t = app(AdsOverview::class)->build(totFilter(['platform' => 'tiktok']))['totals'];

    expect($t['source'])->toBe('ads')->and($t['spend'])->toBe(80.0);
});

it('feeds top accounts and the revenue summary from the control too', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = totAd($acc);
    totAdRow($ad, '2026-09-10', 495, 900);
    totControl($acc, '2026-09-10', 500, 1000);
    $f = totFilter();

    expect(app(TopAccounts::class)->build($f)[0]['spend'])->toBe(500.0)
        ->and(app(TopAccounts::class)->build($f)[0]['purchase_value'])->toBe(1000.0);
    $s = app(RevenueSummary::class)->build($f, false);
    expect($s['spend'])->toBe(500.0)->and($s['platform']['revenue'])->toBe(1000.0);
});
