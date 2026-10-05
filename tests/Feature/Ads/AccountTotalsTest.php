<?php

use App\Ads\Buyers\AssignmentService;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
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

it('falls back to the sum of ads when one day has no control row', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = totAd($acc);
    totAdRow($ad, '2026-09-10', 495);
    totAdRow($ad, '2026-09-11', 495);
    totControl($acc, '2026-09-10', 500);

    $t = app(AdsOverview::class)->build(totFilter())['totals'];

    expect($t['source'])->toBe('ads')->and($t['spend'])->toBe(990.0)->and($t['itemised_gap'])->toBeNull();
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
