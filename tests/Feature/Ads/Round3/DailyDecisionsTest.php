<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Ads\Decisions\DailyDecisions;
use App\Ads\Materials\StockWatcher;
use App\Ads\Reports\AdsFilter;
use App\Models\AdAccount;
use App\Models\AdMaterial;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Round 3: «قرار اليوم» — every running, scored ad gets one decision (scale / cut / keep / fix creative / stock risk) with
 * its evidence line and the action «نفّذ» would send. «now» = 2026-10-06 15:30 Cairo (crSetup).
 */
beforeEach(fn () => crSetup($this));

/** Three active days in the window so the ad passes the scorer gate (spend ≥ 500, days ≥ 3). */
function ddDays(float $spendPerDay, float $valuePerDay, float $purchasesPerDay = 1): array
{
    return [
        '2026-10-03' => [$spendPerDay, $purchasesPerDay, $valuePerDay, 0],
        '2026-10-04' => [$spendPerDay, $purchasesPerDay, $valuePerDay, 0],
        '2026-10-05' => [$spendPerDay, $purchasesPerDay, $valuePerDay, 0],
    ];
}

function ddBuild($user): array
{
    $f = AdsFilter::fromRequest(Request::create('/ads/decisions'), $user, 'last7');

    return collect(app(DailyDecisions::class)->build($user, $f))->keyBy(fn (array $d) => $d['ad']['id'])->all();
}

it('a winner is a «scale» (+20% budget), a loser a «cut» (pause), a middling ad a «keep»', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $winner = crAd($acc, ddDays(1000, 4000), ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    $loser = crAd($acc, ddDays(1000, 200), ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    $middle = crAd($acc, ddDays(1000, 1500), ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    crAd($acc, ddDays(1000, 4000), ['status' => 'PAUSED', 'effective_status' => 'PAUSED']); // not running: no decision

    $d = ddBuild(crAdmin());

    expect($d)->toHaveCount(3);
    expect($d[$winner->id]['kind'])->toBe('scale')->and($d[$winner->id]['action'])->toBe(['type' => 'set_budget', 'pct' => 20])
        ->and($d[$winner->id]['evidence']['spend'])->toBe(3420.0)->and($d[$winner->id]['evidence']['roas'])->toBe(4.0)->and($d[$winner->id]['evidence']['days'])->toBe(3)
        ->and($d[$winner->id]['evidence']['results'])->toBe(3.0)->and($d[$winner->id]['evidence']['trend']['dir'])->toBe('flat')->and($d[$winner->id]['evidence']['tier'])->toBe('winner')
        ->and($d[$winner->id]['ad']['external_id'])->toBe($winner->external_id)->and($d[$winner->id]['can_write'])->toBeTrue();
    expect($d[$loser->id]['kind'])->toBe('cut')->and($d[$loser->id]['action'])->toBe(['type' => 'set_status', 'to' => 'paused'])
        ->and(collect($d[$loser->id]['reasons'])->pluck('key'))->toContain('roas_below');
    expect($d[$middle->id]['kind'])->toBe('keep')->and($d[$middle->id]['action'])->toBeNull();
});

it('a running ad whose product is low or out of stock is a «stock risk» whatever its numbers say, with the product and the stock', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $ad = crAd($acc, ddDays(1000, 4000), ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'name' => 'Noor reel']);
    $product = Product::factory()->create(['title' => 'Abaya Noor']);
    ProductVariant::factory()->create(['product_id' => $product->id, 'inventory_quantity' => 2]);
    AdMaterial::factory()->create(['title' => 'Noor', 'status' => 'live', 'product_id' => $product->id])->ads()->attach($ad->id);
    app(StockWatcher::class)->low();

    $d = ddBuild(crAdmin());

    expect($d[$ad->id]['kind'])->toBe('stock_risk')->and($d[$ad->id]['action'])->toBe(['type' => 'set_status', 'to' => 'paused'])
        ->and($d[$ad->id]['stock'])->toBe(['state' => 'low', 'inventory' => 2, 'threshold' => 10, 'product' => 'Abaya Noor', 'material' => 'Noor'])
        ->and(collect($d[$ad->id]['reasons'])->pluck('key'))->toContain('stock_low');
});

it('orders the day: stock risk, cut, fix creative, scale, keep; and counts per kind', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $keep = crAd($acc, ddDays(1000, 1500), ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    $scale = crAd($acc, ddDays(1000, 4000), ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    $cut = crAd($acc, ddDays(1000, 200), ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    $risk = crAd($acc, ddDays(1000, 4000), ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    $product = Product::factory()->create();
    ProductVariant::factory()->create(['product_id' => $product->id, 'inventory_quantity' => 0]);
    AdMaterial::factory()->create(['status' => 'live', 'product_id' => $product->id])->ads()->attach($risk->id);
    app(StockWatcher::class)->run();

    $admin = crAdmin();
    $f = AdsFilter::fromRequest(Request::create('/ads/decisions'), $admin, 'last7');
    $list = app(DailyDecisions::class)->build($admin, $f);

    expect(array_column(array_column($list, 'ad'), 'id'))->toBe([$risk->id, $cut->id, $scale->id, $keep->id]);
    expect(DailyDecisions::counts($list))->toBe(['stock_risk' => 1, 'cut' => 1, 'fix_creative' => 0, 'scale' => 1, 'keep' => 1]);
});

it('the Decisions page carries the day\'s decisions, the execute flag and the scale percentage', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    crAd($acc, ddDays(1000, 4000), ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    config(['crm.ads.execute' => false]);

    $this->actingAs(crAdmin())->get('/ads/decisions')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Decisions')
        ->has('daily.items', 1)->where('daily.items.0.kind', 'scale')->where('daily.counts.scale', 1)
        ->where('daily.execute', false)->where('daily.scale_pct', 20));
});

it('a media buyer sees only the decisions of the accounts assigned to them', function () {
    $mine = crBuyer();
    crAd($mine['account'], ddDays(1000, 4000), ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);
    crAd(AdAccount::factory()->meta()->create(['currency' => 'EGP']), ddDays(1000, 4000), ['status' => 'ACTIVE', 'effective_status' => 'ACTIVE']);

    expect(ddBuild($mine['user']))->toHaveCount(1);
});
