<?php

use App\Ads\Reports\AdInsights;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Ads\Reports\WinnerScorer;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdDailyMetric;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

function rsnDay(Ad $ad, string $date, array $over = []): void
{
    AdDailyMetric::factory()->create($over + [
        'ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => $date,
        'spend' => 100, 'purchase_value' => 0, 'purchases' => 0, 'impressions' => 1000, 'clicks' => 20, 'reach' => 800,
    ]);
}

function rsnFilter(): AdsFilter
{
    return new AdsFilter(CarbonImmutable::parse('2026-09-17'), CarbonImmutable::parse('2026-09-30'));
}

function rsnReasons(array $row): array
{
    return array_column($row['reasons'], 'params', 'key');
}

it('explains a winner with the threshold it passed and its consistency', function () {
    $acc = AdAccount::factory()->create();
    $ad = Ad::factory()->for($acc, 'account')->create(['name' => 'W']);
    // 14 days (Sep 17-30): spend 5000 total, value 16000; sales on 12 of 14 days
    foreach (CarbonPeriod::create('2026-09-17', '2026-09-30') as $i => $d) {
        $sale = $i < 12;
        rsnDay($ad, $d->toDateString(), [
            'spend' => 5000 / 14, 'purchase_value' => $sale ? 16000 / 12 : 0, 'purchases' => $sale ? 1 : 0,
        ]);
    }

    $row = app(WinnerScorer::class)->build(rsnFilter())[0];
    $r = rsnReasons($row);

    expect($row['tier'])->toBe('winner')
        ->and($r['roas_above']['threshold'])->toBe(2.0)->and($r['roas_above']['days'])->toBe(14)
        ->and($r['roas_above']['roas'])->toBe($row['smoothed_roas'])
        ->and($r['consistency'])->toBe(['days_with_sales' => 12, 'active_days' => 14])
        ->and($r)->toHaveKeys(['spend', 'cpa', 'ctr'])
        ->and($r)->not->toHaveKey('roas_below');
});

it('explains a loser with roas_below', function () {
    $acc = AdAccount::factory()->create();
    $ad = Ad::factory()->for($acc, 'account')->create();
    foreach (CarbonPeriod::create('2026-09-17', '2026-09-30') as $d) {
        rsnDay($ad, $d->toDateString(), ['spend' => 100, 'purchase_value' => 10, 'purchases' => 0]);
    }

    $row = app(WinnerScorer::class)->build(rsnFilter())[0];
    $r = rsnReasons($row);

    expect($row['tier'])->toBe('loser')
        ->and($r['roas_below']['threshold'])->toBe(0.8)
        ->and($r)->not->toHaveKey('roas_above');
});

it('reports trend up when the last 7 days beat the 7 before by 10 percent or more', function () {
    $acc = AdAccount::factory()->create();
    $up = Ad::factory()->for($acc, 'account')->create();
    $down = Ad::factory()->for($acc, 'account')->create();
    $flat = Ad::factory()->for($acc, 'account')->create();
    $new = Ad::factory()->for($acc, 'account')->create();
    foreach (CarbonPeriod::create('2026-09-17', '2026-09-30') as $d) {
        $recent = $d->toDateString() >= '2026-09-24';
        rsnDay($up, $d->toDateString(), ['purchase_value' => $recent ? 330 : 300]);      // 3.3 vs 3.0 -> +10 %
        rsnDay($down, $d->toDateString(), ['purchase_value' => $recent ? 200 : 300]);    // -33.3 %
        rsnDay($flat, $d->toDateString(), ['purchase_value' => $recent ? 305 : 300]);    // +1.7 %
        if ($recent) {
            rsnDay($new, $d->toDateString(), ['purchase_value' => 300]);
        }
    }

    $i = app(AdInsights::class)->forAds([$up->id, $down->id, $flat->id, $new->id], CarbonImmutable::parse('2026-09-30'));

    expect($i[$up->id]['trend'])->toBe(['roas_pct' => 10.0, 'spend_pct' => 0.0, 'dir' => 'up'])
        ->and($i[$down->id]['trend']['dir'])->toBe('down')->and($i[$down->id]['trend']['roas_pct'])->toBe(-33.3)
        ->and($i[$flat->id]['trend']['dir'])->toBe('flat')
        ->and($i[$new->id]['trend'])->toBe(['roas_pct' => null, 'spend_pct' => null, 'dir' => 'flat']);
});

it('flags fatigue when CTR fell 30 percent and frequency is 2.5 or more', function () {
    $acc = AdAccount::factory()->create();
    $tired = Ad::factory()->for($acc, 'account')->create();
    $fresh = Ad::factory()->for($acc, 'account')->create();
    $lowFreq = Ad::factory()->for($acc, 'account')->create();
    foreach (CarbonPeriod::create('2026-09-17', '2026-09-30') as $d) {
        $day = $d->toDateString();
        $last3 = $day >= '2026-09-28';
        $clicks = $last3 ? 15 : 30;                                                     // CTR 3 % -> 1.5 %
        rsnDay($tired, $day, ['impressions' => 1000, 'clicks' => $clicks, 'reach' => 333, 'purchase_value' => 300, 'purchases' => 1]);
        rsnDay($fresh, $day, ['impressions' => 1000, 'clicks' => 30, 'reach' => 333]);
        rsnDay($lowFreq, $day, ['impressions' => 1000, 'clicks' => $clicks, 'reach' => 800]);
    }

    $i = app(AdInsights::class)->forAds([$tired->id, $fresh->id, $lowFreq->id], CarbonImmutable::parse('2026-09-30'));

    expect($i[$tired->id]['fatigue'])->toBe(['flag' => true, 'ctr_drop_pct' => 50.0, 'frequency' => 3.0])
        ->and($i[$fresh->id]['fatigue']['flag'])->toBeFalse()->and($i[$fresh->id]['fatigue']['ctr_drop_pct'])->toBe(0.0)
        ->and($i[$lowFreq->id]['fatigue']['flag'])->toBeFalse()->and($i[$lowFreq->id]['fatigue']['frequency'])->toBe(1.25);

    $row = collect(app(WinnerScorer::class)->build(rsnFilter()))->firstWhere('ad.id', $tired->id);
    expect($row['fatigue']['flag'])->toBeTrue()
        ->and(rsnReasons($row)['fatigue'])->toBe(['ctr_drop' => 50.0, 'frequency' => 3.0]);
});

it('returns the share of spend going to loser ads on the overview', function () {
    $acc = AdAccount::factory()->create();
    $good = Ad::factory()->for($acc, 'account')->create();
    $bad = Ad::factory()->for($acc, 'account')->create();
    foreach (CarbonPeriod::create('2026-09-17', '2026-09-30') as $d) {
        rsnDay($good, $d->toDateString(), ['spend' => 300, 'purchase_value' => 600, 'purchases' => 1]); // 4200 spend
        rsnDay($bad, $d->toDateString(), ['spend' => 100, 'purchase_value' => 10]);                       // 1400 spend, ROAS 0.1
    }

    $totals = app(AdsOverview::class)->build(rsnFilter())['totals'];

    expect($totals['losers_spend_share'])->toBe(0.25);
});

it('has a null losers share without spend', function () {
    expect(app(AdsOverview::class)->build(rsnFilter())['totals']['losers_spend_share'])->toBeNull();
});
