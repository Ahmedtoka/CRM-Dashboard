<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Ads\Reports\AdRowEnricher;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\RunningCreatives;
use App\Models\AdAccount;
use App\Models\AdMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => crSetup($this));

it('adds health, a 14-day series and can_write to each row', function () {
    $w = crBuyer();
    $ad = crAd($w['account'], ['2026-10-04' => [300, 0, 0, 0]]);
    $mat = AdMaterial::factory()->create(['status' => 'live', 'need_stop_at' => now()]);
    DB::table('ad_material_ads')->insert(['ad_material_id' => $mat->id, 'ad_id' => $ad->id]);
    $other = AdAccount::factory()->meta()->create();
    crAd($other, ['2026-10-04' => [300, 0, 0, 0]]);

    $f = AdsFilter::fromRequest(Request::create('/ads/explorer', 'GET', ['range' => 'last7']), $w['user']);
    $rows = app(RunningCreatives::class)->build($f, [])['data'];
    $out = app(AdRowEnricher::class)->enrich($rows, $f, $w['user']);

    expect($out)->toHaveCount(1)
        ->and($out[0]['id'])->toBe($ad->id)
        ->and($out[0]['need_stop'])->toBeTrue()
        ->and($out[0]['health'][0])->toBe('out_of_stock')
        ->and($out[0]['series'])->toHaveCount(14)
        ->and($out[0])->toHaveKey('can_write');
});

it('returns an empty list for no rows', function () {
    $f = AdsFilter::fromRequest(Request::create('/ads'), crAdmin());
    expect(app(AdRowEnricher::class)->enrich([], $f, crAdmin()))->toBe([]);
});
