<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Ads\Control\StopAdvisor;
use App\Ads\Reports\AdsFilter;
use App\Models\AdAccount;
use App\Models\AdMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => crSetup($this));

function saSuggest(): array
{
    return app(StopAdvisor::class)->suggest(AdsFilter::fromRequest(Request::create('/ads/decisions', 'GET', ['from' => '2026-09-23', 'to' => '2026-10-06']), crAdmin()));
}

it('never suggests stopping a Messages ad for zero purchases (quick win 7)', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $msgObjective = crAd($acc, ['2026-10-01' => [1500, 0, 0, 0]], [], 'MESSAGES');
    $msgChats = crAd($acc, ['2026-10-01' => [1500, 0, 0, 30]]);   // Sales objective, Messenger destination
    $sales = crAd($acc, ['2026-10-01' => [1500, 0, 0, 0]]);

    $ids = collect(saSuggest())->pluck('ad_id')->all();

    expect($ids)->toContain($sales->id)->not->toContain($msgObjective->id)->not->toContain($msgChats->id);
});

it('still suggests a Messages ad whose material ran out of stock, with card data', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $ad = crAd($acc, ['2026-10-01' => [100, 0, 0, 5]], ['thumbnail_url' => 'https://x.test/t.jpg'], 'MESSAGES');
    $mat = AdMaterial::factory()->create(['status' => 'live']);
    $mat->forceFill(['need_stop_at' => now()])->save();
    DB::table('ad_material_ads')->insert(['ad_material_id' => $mat->id, 'ad_id' => $ad->id]);

    $s = collect(saSuggest())->firstWhere('ad_id', $ad->id);

    expect($s['reasons'][0]['key'])->toBe('need_stop')->and($s['objective'])->toBe('messages')
        ->and($s['thumbnail_url'])->toBe('https://x.test/t.jpg')->and($s)->toHaveKeys(['campaign', 'status']);
});
