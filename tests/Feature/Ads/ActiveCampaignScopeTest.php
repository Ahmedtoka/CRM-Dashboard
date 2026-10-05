<?php

use App\Ads\Buyers\AssignmentService;
use App\Ads\Control\StopAdvisor;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Ads\Reports\AdsQuery;
use App\Ads\Reports\BuyerScorecard;
use App\Ads\Reports\CampaignTree;
use App\Ads\Reports\RunningCreatives;
use App\Ads\Reports\TopAccounts;
use App\Ads\Reports\WinnerScorer;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdMaterial;
use App\Models\Customer;
use App\Models\MediaBuyer;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * One account, three campaigns: C1 ACTIVE (ad spend 100), C2 PAUSED (60), C3 ARCHIVED (40), spread over 5 days
 * so a spend of 100 per ad x 5 days would pass the winner gate when $perDay is raised.
 *
 * @return array{acc:AdAccount, ads:array<string, Ad>, admin:User}
 */
function scopeWorld(float $c1 = 100, float $c2 = 60, float $c3 = 40, int $days = 1): array
{
    $acc = AdAccount::factory()->create();
    $ads = [];
    foreach (['C1' => ['ACTIVE', $c1], 'C2' => ['PAUSED', $c2], 'C3' => ['ARCHIVED', $c3]] as $name => [$status, $spend]) {
        $camp = AdCampaign::factory()->create(['ad_account_id' => $acc->id, 'name' => $name, 'status' => $status]);
        $ad = Ad::factory()->for($acc, 'account')->create(['ad_campaign_id' => $camp->id, 'name' => 'ad-'.$name]);
        for ($i = 0; $i < $days; $i++) {
            AdDailyMetric::factory()->create([
                'ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => CarbonImmutable::parse('2026-09-10')->addDays($i)->toDateString(),
                'spend' => $spend / $days, 'purchase_value' => $spend / $days * 2, 'purchases' => 1, 'impressions' => 1000, 'clicks' => 10, 'reach' => 800,
            ]);
        }
        $ads[$name] = $ad;
    }

    return ['acc' => $acc, 'ads' => $ads, 'admin' => User::factory()->create(['role' => UserRole::Admin])];
}

function scopeFilter(User $u): AdsFilter
{
    return AdsFilter::fromRequest(Request::create('/ads', 'GET', ['from' => '2026-09-01', 'to' => '2026-09-30']), $u);
}

it('keeps all spend in the overview totals and reports what sits outside active campaigns', function () {
    $w = scopeWorld();
    $t = app(AdsOverview::class)->build(scopeFilter($w['admin']))['totals'];

    expect($t['spend'])->toBe(200.0)->and($t['spend_outside_active'])->toBe(100.0);
});

it('lists only the active campaign ads in creatives, campaigns and winners', function () {
    $w = scopeWorld(600, 600, 600, 5);
    $f = scopeFilter($w['admin']);

    $creatives = app(RunningCreatives::class)->build($f, ['status' => 'all']);
    expect(array_column($creatives['data'], 'name'))->toBe(['ad-C1'])->and($creatives['meta']['total'])->toBe(1);

    $tree = app(CampaignTree::class)->build($f);
    expect(array_column($tree, 'name'))->toBe(['C1']);

    $winners = app(WinnerScorer::class)->build($f);
    expect(array_column(array_column($winners, 'ad'), 'name'))->toBe(['ad-C1']);
    // the same filter without the scope sees all three, so the scope is what removed them
    expect(app(WinnerScorer::class)->build($f->allSpend()))->toHaveCount(3);
});

it('keeps all spend in top accounts and buyer cards', function () {
    $w = scopeWorld();
    $buyer = MediaBuyer::factory()->create();
    app(AssignmentService::class)->assign($w['acc'], $buyer, CarbonImmutable::parse('2026-09-01'));
    $f = scopeFilter($w['admin']);

    expect(app(TopAccounts::class)->build($f)[0]['spend'])->toBe(200.0);
    $card = collect(app(BuyerScorecard::class)->build($f))->firstWhere('buyer_id', $buyer->id);
    expect($card['spend'])->toBe(200.0);
});

it('shows the numbers of an ad in a non-active campaign when opened directly', function () {
    $w = scopeWorld();

    $this->actingAs($w['admin'])->getJson('/ads/creatives/'.$w['ads']['C2']->id.'?from=2026-09-01&to=2026-09-30')
        ->assertOk()->assertJsonPath('spend', 60);
});

it('counts an order of a paused campaign in overview revenue but not in the active-only order list', function () {
    $w = scopeWorld();
    $o = Order::factory()->create([
        'customer_id' => Customer::factory()->create()->id, 'status' => OrderStatus::Confirmed, 'total' => 500,
        'ad_id' => $w['ads']['C2']->id, 'placed_at' => '2026-09-12 10:00:00',
    ]);
    $f = scopeFilter($w['admin']);

    $totals = app(AdsOverview::class)->build($f)['totals'];
    expect($totals['real_orders'])->toBe(1)->and($totals['real_revenue'])->toBe(500.0);
    expect(app(AdsQuery::class)->orders($f)->pluck('id')->all())->toBe([])
        ->and(app(AdsQuery::class)->orders($f->allSpend())->pluck('id')->all())->toBe([$o->id]);
});

it('never suggests stopping an ad of a campaign that is not active', function () {
    $acc = AdAccount::factory()->meta()->create();
    $active = AdCampaign::factory()->create(['ad_account_id' => $acc->id, 'status' => 'ACTIVE']);
    $paused = AdCampaign::factory()->create(['ad_account_id' => $acc->id, 'status' => 'PAUSED']);
    $in = Ad::factory()->for($acc, 'account')->create(['name' => 'In active', 'ad_campaign_id' => $active->id]);
    $out = Ad::factory()->for($acc, 'account')->create(['name' => 'In paused', 'ad_campaign_id' => $paused->id]);
    $material = AdMaterial::factory()->create(['status' => 'activated', 'need_stop_at' => now()]);
    $material->ads()->attach($out->id);
    foreach (CarbonPeriod::create('2026-09-17', '2026-09-30') as $d) {
        foreach ([$in, $out] as $ad) {
            AdDailyMetric::factory()->create([
                'ad_id' => $ad->id, 'ad_account_id' => $acc->id, 'date' => $d->toDateString(), 'spend' => 100,
                'purchase_value' => 10, 'purchases' => 1, 'impressions' => 1000, 'clicks' => 10, 'reach' => 800,
            ]);
        }
    }
    $f = new AdsFilter(CarbonImmutable::parse('2026-09-17'), CarbonImmutable::parse('2026-09-30'), activeCampaignsOnly: true);

    $names = array_column(app(StopAdvisor::class)->suggest($f), 'name');

    expect($names)->toBe(['In active']);
});
