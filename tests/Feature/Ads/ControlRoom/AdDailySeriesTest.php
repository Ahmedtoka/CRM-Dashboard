<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Ads\Reports\AdDailySeries;
use App\Models\AdAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => crSetup($this));

it('returns 14 zero-filled days per ad with real ROAS, in two grouped queries', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $a = crAd($acc, ['2026-10-04' => [200, 0, 0, 0], '2026-10-05' => [100, 0, 0, 0]]);
    $b = crAd($acc, ['2026-10-05' => [50, 0, 0, 0]]);
    crOrder($a, '2026-10-04 20:00', 500);
    [$from, $to] = AdDailySeries::window(CarbonImmutable::parse('2026-10-05', 'Africa/Cairo'));

    DB::enableQueryLog();
    $series = app(AdDailySeries::class)->forAds([$a->id, $b->id], $from, $to);
    $queries = count(DB::getQueryLog());

    expect($queries)->toBe(2)
        ->and($series[$a->id])->toHaveCount(14)
        ->and($series[$a->id][0])->toBe(['date' => '2026-09-22', 'spend' => 0.0, 'roas' => null])
        ->and($series[$a->id][12])->toBe(['date' => '2026-10-04', 'spend' => 200.0, 'roas' => 2.5])
        ->and($series[$a->id][13])->toBe(['date' => '2026-10-05', 'spend' => 100.0, 'roas' => 0.0])
        ->and($series[$b->id][13]['spend'])->toBe(50.0);
});

it('returns an empty array for no ids without querying', function () {
    DB::enableQueryLog();
    expect(app(AdDailySeries::class)->forAds([], CarbonImmutable::now(), CarbonImmutable::now()))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

it('gives no real ROAS on a non-EGP account (review I2)', function () {
    $usd = AdAccount::factory()->meta()->create(['currency' => 'USD']);
    $ad = crAd($usd, ['2026-10-04' => [20, 0, 0, 0]]);
    crOrder($ad, '2026-10-04 20:00', 1000);
    [$from, $to] = AdDailySeries::window(CarbonImmutable::parse('2026-10-05', 'Africa/Cairo'));

    expect(app(AdDailySeries::class)->forAds([$ad->id], $from, $to)[$ad->id][12])->toBe(['date' => '2026-10-04', 'spend' => 20.0, 'roas' => null]);
});

it('keeps a scoped series to the days the viewer held the account (review I3)', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $old = crBuyer($acc, '2026-09-01', '2026-09-30');
    $new = crBuyer($acc, '2026-10-01');
    $ad = crAd($acc, ['2026-09-28' => [100, 0, 0, 0], '2026-10-02' => [900, 0, 0, 0]]);
    crOrder($ad, '2026-10-02 14:00', 1800);
    $day = fn (array $series, string $d) => collect($series)->firstWhere('date', $d);

    // The former holder widens the drawer range: still only their own days.
    $res = $this->actingAs($old['user'])->getJson("/ads/ad/{$ad->id}?from=2026-09-01&to=2026-10-06")->assertOk();
    expect($day($res->json('ad.series'), '2026-09-28')['spend'])->toEqual(100)
        ->and($day($res->json('ad.series'), '2026-10-02')['spend'])->toEqual(0)
        ->and($day($res->json('ad.series'), '2026-10-02')['roas'])->toBeNull();

    $res = $this->actingAs($new['user'])->getJson("/ads/ad/{$ad->id}?from=2026-09-01&to=2026-10-06")->assertOk();
    expect($day($res->json('ad.series'), '2026-09-28')['spend'])->toEqual(0)
        ->and($day($res->json('ad.series'), '2026-10-02')['spend'])->toEqual(900)
        ->and($day($res->json('ad.series'), '2026-10-02')['roas'])->toEqual(2);

    // The explorer sparkline uses the same scope.
    $row = $this->actingAs($old['user'])->get('/ads/explorer?from=2026-09-20&to=2026-10-06&status=all')->viewData('page')['props']['result']['data'][0];
    expect($day($row['series'], '2026-10-02')['spend'])->toEqual(0)->and($day($row['series'], '2026-09-28')['spend'])->toEqual(100);
});
