<?php

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsQuery;
use App\Ads\Reports\RevenueSummary;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Ad;
use App\Models\AdDailyMetric;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Refund;
use Carbon\CarbonImmutable;

/*
 * A3 (F-005, F-043): one net-revenue definition.
 * - A store order's `total` is Shopify current_total_price (OrderMapper.php:410), already after refunds: no subtraction.
 * - A chat order's `total` is set by the CRM and never refreshed from Shopify (OrderMapper::updateChatOrder), so its
 *   Shopify refunds are subtracted once, never below 0.
 * - awaiting_payment, cancelled and failed orders and courier-returned shipments are not real revenue.
 */
function nrRange(): AdsFilter
{
    return new AdsFilter(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
}

function nrAd(): Ad
{
    $ad = Ad::factory()->create();
    AdDailyMetric::factory()->create([
        'ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => '2026-09-10',
        'spend' => 1000, 'purchase_value' => 3000, 'purchases' => 6, 'impressions' => 1000, 'clicks' => 10, 'reach' => 500,
    ]);

    return $ad;
}

function nrOrder(array $attrs): Order
{
    return Order::factory()->create(array_merge([
        'customer_id' => Customer::factory()->create()->id,
        'status' => OrderStatus::Confirmed,
        'source' => OrderSource::Store,
        'subtotal' => 0, 'shipping_fee' => 0,
        'placed_at' => '2026-09-10 10:00:00',
    ], $attrs));
}

it('does not subtract refunds again from a store order (total is already Shopify current total)', function () {
    $ad = nrAd();
    $o = nrOrder(['ad_id' => $ad->id, 'total' => 700]); // 1000 before a 300 refund
    Refund::factory()->create(['order_id' => $o->id, 'amount' => 300]);

    $orders = app(AdsQuery::class)->orders(nrRange());

    expect($orders)->toHaveCount(1)->and($orders[0]['net'])->toBe(700.0);
});

it('counts a fully refunded store order as 0, never negative', function () {
    $ad = nrAd();
    $o = nrOrder(['ad_id' => $ad->id, 'total' => 0]);
    Refund::factory()->create(['order_id' => $o->id, 'amount' => 1000]);

    expect(app(AdsQuery::class)->orders(nrRange())[0]['net'])->toBe(0.0)
        ->and(app(RevenueSummary::class)->build(nrRange(), true)['crm']['revenue'])->toBe(0.0);
});

it('subtracts Shopify refunds once from a chat order whose total the CRM keeps, never below 0', function () {
    $ad = nrAd();
    $a = nrOrder(['ad_id' => $ad->id, 'total' => 1000, 'source' => OrderSource::Chat]);
    Refund::factory()->create(['order_id' => $a->id, 'amount' => 300]);
    $b = nrOrder(['ad_id' => $ad->id, 'total' => 500, 'source' => OrderSource::Chat]);
    Refund::factory()->create(['order_id' => $b->id, 'amount' => 600]);

    $net = app(AdsQuery::class)->orders(nrRange())->pluck('net', 'id');

    expect($net[$a->id])->toBe(700.0)->and($net[$b->id])->toBe(0.0);
});

it('leaves awaiting-payment orders out of real orders and of ordered conversations', function () {
    $ad = nrAd();
    $customer = Customer::factory()->create();
    Conversation::factory()->create([
        'customer_id' => $customer->id, 'ad_id' => $ad->external_id, 'ad_attributed_at' => '2026-09-05 10:00:00',
    ]);
    nrOrder(['ad_id' => $ad->id, 'customer_id' => $customer->id, 'total' => 900, 'status' => OrderStatus::AwaitingPayment]);

    expect(app(AdsQuery::class)->orders(nrRange()))->toHaveCount(0);
    $convs = app(AdsQuery::class)->conversations(nrRange());
    expect($convs)->toHaveCount(1)->and($convs[0]['ordered'])->toBeFalse();

    nrOrder(['ad_id' => $ad->id, 'customer_id' => $customer->id, 'total' => 400]);
    expect(app(AdsQuery::class)->conversations(nrRange())[0]['ordered'])->toBeTrue();
});

it('uses the same real-order definition for the store side and the CRM side', function () {
    $ad = nrAd();
    nrOrder(['ad_id' => $ad->id, 'total' => 1000]);                                          // real
    nrOrder(['ad_id' => $ad->id, 'total' => 200, 'status' => OrderStatus::AwaitingPayment]); // unpaid
    nrOrder(['ad_id' => $ad->id, 'total' => 300, 'status' => OrderStatus::Failed]);
    nrOrder(['ad_id' => $ad->id, 'total' => 400, 'status' => OrderStatus::Cancelled]);
    nrOrder(['ad_id' => $ad->id, 'total' => 500, 'shipment_status' => ShipmentStatus::Returned->value]);
    $chat = nrOrder(['ad_id' => $ad->id, 'total' => 600, 'source' => OrderSource::Chat]);
    Refund::factory()->create(['order_id' => $chat->id, 'amount' => 100]);

    $s = app(RevenueSummary::class)->build(nrRange(), true);

    expect(AdsQuery::NOT_REAL_STATUSES)->toEqualCanonicalizing(['awaiting_payment', 'cancelled', 'failed'])
        ->and($s['crm'])->toMatchArray(['orders' => 2.0, 'revenue' => 1500.0])
        ->and($s['store'])->toBe(['orders' => 2, 'revenue' => 1500.0])
        ->and($s['gaps']['crm_vs_store'])->toBe(0.0);
});
