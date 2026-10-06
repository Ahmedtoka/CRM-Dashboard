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
