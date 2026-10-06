<?php

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\RevenueSummary;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdDailyMetric;
use App\Models\Customer;
use App\Models\MediaBuyer;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function rsRange(): AdsFilter
{
    return new AdsFilter(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
}

function rsOrder(array $attrs): Order
{
    return Order::factory()->create(array_merge([
        'customer_id' => Customer::factory()->create()->id,
        'status' => OrderStatus::Confirmed,
        'source' => OrderSource::Store,
        'subtotal' => 0, 'shipping_fee' => 0,
    ], $attrs));
}

function rsWorld(): Ad
{
    $ad = Ad::factory()->create();
    AdDailyMetric::factory()->create([
        'ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => '2026-09-10',
        'spend' => 1000, 'purchase_value' => 3000, 'purchases' => 6,
        'impressions' => 1000, 'clicks' => 10, 'reach' => 500,
    ]);

    return $ad;
}

it('builds the three-way summary', function () {
    $ad = rsWorld();
    rsOrder(['ad_id' => $ad->id, 'total' => 500, 'source' => OrderSource::Chat, 'placed_at' => '2026-09-10 10:00:00']);
    rsOrder(['ad_id' => $ad->id, 'total' => 700, 'placed_at' => '2026-09-11 10:00:00']);
    rsOrder(['total' => 800, 'placed_at' => '2026-09-12 10:00:00']);
    rsOrder(['total' => 9999, 'cancelled_at' => '2026-09-12 12:00:00', 'status' => OrderStatus::Cancelled, 'placed_at' => '2026-09-12 10:00:00']);

    $s = app(RevenueSummary::class)->build(rsRange(), true);

    expect($s['spend'])->toBe(1000.0)->and($s['spend_tax'])->toBe(1140.0)
        ->and($s['store'])->toBe(['orders' => 3, 'revenue' => 2000.0])
        ->and($s['crm']['orders'])->toBe(2.0)->and($s['crm']['revenue'])->toBe(1200.0)->and($s['crm']['chat_orders'])->toBe(1)
        ->and($s['platform'])->toBe(['purchases' => 6.0, 'revenue' => 3000.0])
        ->and($s['roas'])->toBe(['store' => 2.0, 'crm' => 1.2, 'platform' => 3.0])
        ->and($s['gaps']['platform_vs_crm'])->toBe(1800.0)->and($s['gaps']['platform_vs_crm_pct'])->toBe(1.5)
        ->and($s['gaps']['crm_vs_store'])->toBe(-800.0)->and($s['gaps']['crm_vs_store_pct'])->toBe(-0.4);
});

it('hides store totals when withStore is false', function () {
    $ad = rsWorld();
    rsOrder(['ad_id' => $ad->id, 'total' => 500, 'placed_at' => '2026-09-10 10:00:00']);

    $s = app(RevenueSummary::class)->build(rsRange(), false);

    expect($s['store'])->toBeNull()->and($s['roas']['store'])->toBeNull()->and($s['gaps']['crm_vs_store'])->toBeNull()
        ->and($s['crm']['orders'])->toBe(1.0);
});

it('uses Cairo day boundaries for the store totals', function () {
    rsWorld();
    rsOrder(['total' => 100, 'placed_at' => '2026-09-30 20:30:00']); // 23:30 Cairo (UTC+3) on the last day: in
    rsOrder(['total' => 200, 'placed_at' => '2026-09-30 21:30:00']); // 00:30 Cairo Oct 1: out
    rsOrder(['total' => 400, 'placed_at' => '2026-08-31 21:30:00']); // 00:30 Cairo Sep 1: in
    rsOrder(['total' => 800, 'placed_at' => '2026-08-31 20:30:00']); // 23:30 Cairo Aug 31: out

    $s = app(RevenueSummary::class)->build(rsRange(), true);

    expect($s['store'])->toBe(['orders' => 2, 'revenue' => 500.0]);
});

it('returns null ratios when there is no spend or store revenue', function () {
    $s = app(RevenueSummary::class)->build(rsRange(), true);

    expect($s['store'])->toBe(['orders' => 0, 'revenue' => 0.0])->and($s['roas'])->toBe(['store' => null, 'crm' => null, 'platform' => null])
        ->and($s['gaps']['platform_vs_crm_pct'])->toBeNull()->and($s['gaps']['crm_vs_store_pct'])->toBeNull();
});

it('shows store totals to admins and hides them from media buyers on the overview', function () {
    rsWorld();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin)->get('/ads/numbers?from=2026-09-01&to=2026-09-30')
        ->assertInertia(fn (Assert $p) => $p->where('summary.store.orders', 0));

    $buyer = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $this->actingAs($buyer)->get('/ads/numbers?from=2026-09-01&to=2026-09-30')
        ->assertInertia(fn (Assert $p) => $p->where('summary.store', null)->has('summary.platform'));
});

it('puts the summary on the buyer page, scoped to the buyer', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $buyer = MediaBuyer::factory()->create();

    $this->actingAs($admin)->get('/ads/buyers/'.$buyer->id.'?from=2026-09-01&to=2026-09-30')
        ->assertOk()->assertInertia(fn (Assert $p) => $p->has('summary.crm')->where('summary.store.orders', 0));
});
