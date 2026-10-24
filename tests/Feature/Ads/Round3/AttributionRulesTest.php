<?php

use App\Ads\Attribution\AdCostPerOrder;
use App\Ads\Attribution\OrderAttribution;
use App\Enums\OrderStatus;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\AdSet;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Round 3 attribution rules, in order: chat referral (the customer's latest ad referral within the inbox window) →
 * utm ad id → utm ad set id (utm_term on Meta) → utm campaign → none. orders.ad_attribution stores the rule that won.
 */
function arRange(): array
{
    return [CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30')];
}

function arOrder(array $attrs = []): Order
{
    return Order::factory()->create(array_merge([
        'customer_id' => Customer::factory()->create()->id,
        'placed_at' => '2026-09-10 10:00',
        'status' => OrderStatus::Confirmed,
        'total' => 1000,
    ], $attrs));
}

/** @return array{acc: AdAccount, camp: AdCampaign, set: AdSet, a: Ad, b: Ad, solo: AdSet, c: Ad} */
function arWorld(): array
{
    $acc = AdAccount::factory()->meta()->create(['currency' => 'EGP']);
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['external_id' => '5000', 'name' => 'SALES | R3']);
    $set = AdSet::factory()->create(['ad_campaign_id' => $camp->id, 'external_id' => '6000']);
    $solo = AdSet::factory()->create(['ad_campaign_id' => $camp->id, 'external_id' => '6001']);
    $a = Ad::factory()->for($acc, 'account')->create(['external_id' => '7001', 'ad_campaign_id' => $camp->id, 'ad_set_id' => $set->id]);
    $b = Ad::factory()->for($acc, 'account')->create(['external_id' => '7002', 'ad_campaign_id' => $camp->id, 'ad_set_id' => $set->id]);
    $c = Ad::factory()->for($acc, 'account')->create(['external_id' => '7003', 'ad_campaign_id' => $camp->id, 'ad_set_id' => $solo->id]);

    return compact('acc', 'camp', 'set', 'a', 'b', 'solo', 'c');
}

it('a chat referral within the window beats a utm ad id: the customer came through the chat ad', function () {
    $w = arWorld();
    $c = Customer::factory()->create();
    Conversation::factory()->create(['customer_id' => $c->id, 'ad_id' => '7002', 'ad_attributed_at' => '2026-09-09 10:00']);
    $o = arOrder(['customer_id' => $c->id, 'utm_content' => '7001']);

    [$from, $to] = arRange();
    app(OrderAttribution::class)->run($from, $to);

    expect($o->fresh()->ad_id)->toBe($w['b']->id)->and($o->fresh()->ad_attribution)->toBe('inbox')->and($o->fresh()->ad_set_id)->toBe($w['set']->id);
});

it('utm_term naming an ad set attributes the order to that ad set, and to its only ad when it has exactly one', function () {
    $w = arWorld();
    $single = arOrder(['utm_term' => '6001', 'utm_campaign' => '5000']);
    $shared = arOrder(['utm_term' => '6000', 'utm_campaign' => '5000']);

    [$from, $to] = arRange();
    expect(app(OrderAttribution::class)->run($from, $to))->toBe(2);

    expect($single->fresh()->ad_id)->toBe($w['c']->id)->and($single->fresh()->ad_set_id)->toBe($w['solo']->id)
        ->and($single->fresh()->ad_campaign_id)->toBe($w['camp']->id)->and($single->fresh()->ad_attribution)->toBe('utm_adset');
    expect($shared->fresh()->ad_id)->toBeNull()->and($shared->fresh()->ad_set_id)->toBe($w['set']->id)
        ->and($shared->fresh()->ad_campaign_id)->toBe($w['camp']->id)->and($shared->fresh()->ad_attribution)->toBe('utm_adset');
});

it('a utm ad id still wins over the ad set id, and the ad set is filled from the ad', function () {
    $w = arWorld();
    $o = arOrder(['utm_content' => '7001', 'utm_term' => '6001']);

    [$from, $to] = arRange();
    app(OrderAttribution::class)->run($from, $to);

    expect($o->fresh()->ad_id)->toBe($w['a']->id)->and($o->fresh()->ad_set_id)->toBe($w['set']->id)->and($o->fresh()->ad_attribution)->toBe('utm_ad');
});

it('without --force a campaign-level order placed recently is upgraded to its ad set, and a chat referral is never replaced', function () {
    $w = arWorld();
    $this->travelTo('2026-09-20 12:00');
    $campaignLevel = arOrder(['utm_term' => '6001', 'utm_campaign' => '5000', 'ad_campaign_id' => $w['camp']->id, 'ad_attribution' => 'utm_campaign']);
    $cust = Customer::factory()->create();
    Conversation::factory()->create(['customer_id' => $cust->id, 'ad_id' => '7002', 'ad_attributed_at' => '2026-09-09 10:00']);
    $chat = arOrder(['customer_id' => $cust->id, 'utm_content' => '7001', 'ad_id' => $w['b']->id, 'ad_campaign_id' => $w['camp']->id, 'ad_attribution' => 'inbox']);

    [$from, $to] = arRange();
    expect(app(OrderAttribution::class)->run($from, $to))->toBe(1);

    expect($campaignLevel->fresh()->ad_attribution)->toBe('utm_adset')->and($campaignLevel->fresh()->ad_id)->toBe($w['c']->id)
        ->and($chat->fresh()->ad_attribution)->toBe('inbox')->and($chat->fresh()->ad_id)->toBe($w['b']->id);
});

it('ad cost per order: the attributed object\'s spend with tax on the order\'s Cairo day, split over its orders of that day', function () {
    $w = arWorld();
    config(['crm.ads.tax_rate' => 0.14]);
    AdDailyMetric::factory()->create(['ad_id' => $w['a']->id, 'ad_account_id' => $w['acc']->id, 'date' => '2026-09-10', 'spend' => 1000]);
    AdDailyMetric::factory()->create(['ad_id' => $w['b']->id, 'ad_account_id' => $w['acc']->id, 'date' => '2026-09-10', 'spend' => 500]);
    $o1 = arOrder(['utm_content' => '7001']);
    $o2 = arOrder(['utm_content' => '7001']);
    $o3 = arOrder(['utm_term' => '6000']);          // ad set level (two ads in the set)
    $o4 = arOrder(['utm_campaign' => '5000']);      // campaign level
    $none = arOrder([]);
    $cancelled = arOrder(['utm_content' => '7001', 'status' => OrderStatus::Cancelled]);

    [$from, $to] = arRange();
    app(OrderAttribution::class)->run($from, $to);
    expect(app(AdCostPerOrder::class)->run($from, $to))->toBe(4);

    // ad A: 1000 × 1.14 = 1140 over its 2 orders; the set: 1500 × 1.14 = 1710 over 3 orders (its ads' orders included);
    // the campaign: 1710 over its 4 orders. Cancelled and unattributed orders carry no cost.
    expect((float) $o1->fresh()->ad_cost_per_order)->toBe(570.0)->and((float) $o2->fresh()->ad_cost_per_order)->toBe(570.0)
        ->and((float) $o3->fresh()->ad_cost_per_order)->toBe(570.0)->and((float) $o4->fresh()->ad_cost_per_order)->toBe(427.5)
        ->and($none->fresh()->ad_cost_per_order)->toBeNull()->and($cancelled->fresh()->ad_cost_per_order)->toBeNull();
});

it('the hourly command attributes, then computes the cost per order', function () {
    $w = arWorld();
    $this->travelTo('2026-09-11 12:00');
    AdDailyMetric::factory()->create(['ad_id' => $w['a']->id, 'ad_account_id' => $w['acc']->id, 'date' => '2026-09-10', 'spend' => 1000]);
    $o = arOrder(['utm_content' => '7001']);

    $this->artisan('ads:attribute-orders', ['--days' => 5])->assertSuccessful()->expectsOutputToContain('Attributed 1 order(s).')->expectsOutputToContain('cost per order');

    expect((float) $o->fresh()->ad_cost_per_order)->toBe(1140.0);
});

it('per-ad numbers carry conversations, real orders, Shopify revenue, spend, CAC and real ROAS', function () {
    $w = arWorld();
    $this->travelTo('2026-09-11 12:00');
    AdDailyMetric::factory()->create(['ad_id' => $w['a']->id, 'ad_account_id' => $w['acc']->id, 'date' => '2026-09-10', 'spend' => 1000, 'purchases' => 3, 'purchase_value' => 6000]);
    arOrder(['utm_content' => '7001', 'total' => 800]);
    arOrder(['utm_content' => '7001', 'total' => 1200]);
    [$from, $to] = arRange();
    app(OrderAttribution::class)->run($from, $to);
    $admin = \App\Models\User::factory()->adsAuthority()->create(['role' => \App\Enums\UserRole::Admin]);

    $this->withoutVite();
    $rows = $this->actingAs($admin)->get('/ads/explorer?range=last7&status=all')->assertOk()->viewData('page')['props']['result']['data'];
    $row = collect($rows)->firstWhere('id', $w['a']->id);

    expect($row['real_orders'])->toBe(2)->and($row['spend'])->toBe(1000.0)->and($row['spend_tax'])->toBe(1140.0)
        ->and($row['cac'])->toBe(570.0)->and($row['real_revenue'])->toBeGreaterThan(0)->and($row)->toHaveKeys(['conversations', 'real_roas']);
});
