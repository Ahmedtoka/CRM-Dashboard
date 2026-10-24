<?php

require_once __DIR__.'/../../../Support/AdsControlRoom.php';

use App\Ads\Attribution\OrderAttribution;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AttributionBreakdown;
use App\Enums\OrderStatus;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdSet;
use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => crSetup($this));

function r3AttrOrder(array $attrs = []): Order
{
    return Order::factory()->create(array_merge([
        'customer_id' => Customer::factory()->create()->id, 'placed_at' => '2026-10-04 10:00', 'status' => OrderStatus::Confirmed, 'total' => 1000,
    ], $attrs));
}

it('counts real orders and their Shopify revenue by the rule that attributed them, with the share left unattributed', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['external_id' => '5000']);
    Ad::factory()->for($acc, 'account')->create(['external_id' => '7001', 'ad_campaign_id' => $camp->id]);
    r3AttrOrder(['utm_content' => '7001', 'total' => 1000]);
    r3AttrOrder(['utm_content' => '7001', 'total' => 500]);
    r3AttrOrder(['utm_campaign' => '5000', 'total' => 700]);
    r3AttrOrder(['total' => 300]);                                              // no evidence
    r3AttrOrder(['utm_content' => '7001', 'status' => OrderStatus::Cancelled]); // not a real order
    app(OrderAttribution::class)->run(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-06'));

    $f = AdsFilter::fromRequest(Request::create('/ads/numbers?range=this_month'), crAdmin(), 'this_month');
    $b = app(AttributionBreakdown::class)->build($f);

    expect($b['total_orders'])->toBe(4)->and($b['attributed_orders'])->toBe(3)->and($b['unattributed_orders'])->toBe(1)
        ->and($b['attributed_pct'])->toBe(75.0)->and($b['total_revenue'])->toBe(2500.0)->and($b['attributed_revenue'])->toBe(2200.0);
    $rules = collect($b['rules'])->keyBy('rule');
    expect($rules['utm_ad']['orders'])->toBe(2)->and($rules['utm_ad']['revenue'])->toBe(1500.0)
        ->and($rules['utm_campaign']['orders'])->toBe(1)->and($rules['inbox']['orders'])->toBe(0)->and($rules['utm_adset']['orders'])->toBe(0);
});

it('the Numbers page carries the attribution breakdown for managers only', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    crAd($acc, ['2026-10-02' => [300, 1, 100, 0]]);

    $this->actingAs(crAdmin())->get('/ads/numbers')->assertInertia(fn (Assert $p) => $p->has('attribution.rules', 4)->has('attribution.attributed_pct'));
    $this->actingAs(crBuyer()['user'])->get('/ads/numbers')->assertInertia(fn (Assert $p) => $p->where('attribution', null));
});

it('ads:backfill-attribution re-reads old orders beyond the usual 35 days, upgrades weaker rules and writes the cost per order', function () {
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['external_id' => '5000']);
    $set = AdSet::factory()->create(['ad_campaign_id' => $camp->id, 'external_id' => '6000']);
    Ad::factory()->for($acc, 'account')->create(['external_id' => '7001', 'ad_campaign_id' => $camp->id, 'ad_set_id' => $set->id]);
    Ad::factory()->for($acc, 'account')->create(['external_id' => '7002', 'ad_campaign_id' => $camp->id, 'ad_set_id' => $set->id]);
    $old = r3AttrOrder(['placed_at' => '2026-08-20 10:00', 'utm_campaign' => '5000', 'utm_term' => '6000', 'ad_campaign_id' => $camp->id, 'ad_attribution' => 'utm_campaign']);

    $this->artisan('ads:attribute-orders', ['--days' => 90])->assertSuccessful();
    expect($old->fresh()->ad_attribution)->toBe('utm_campaign'); // beyond the 35-day re-check window: left alone

    $this->artisan('ads:backfill-attribution', ['--days' => 90])->assertSuccessful()->expectsOutputToContain('Backfill');
    expect($old->fresh()->ad_attribution)->toBe('utm_adset')->and($old->fresh()->ad_set_id)->toBe($set->id)->and($old->fresh()->ad_cost_per_order)->not->toBeNull();
});
