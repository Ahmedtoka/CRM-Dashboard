<?php

use App\Ads\Alerts\AlertData;
use App\Models\AdAccountDaily;
use App\Models\AdsSyncRun;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('lists only ads that really run', function () {
    $acc = W::account();
    $live = W::ad($acc);
    W::ad($acc, 'OUTCOME_SALES', ['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
    W::ad($acc, 'OUTCOME_SALES', ['effective_status' => 'CAMPAIGN_PAUSED']);
    $tiktok = W::ad($acc, 'OUTCOME_SALES', ['status' => 'ENABLE', 'effective_status' => null]);

    expect(app(AlertData::class)->liveAds($acc->id)->keys()->sort()->values()->all())->toBe([$live->id, $tiktok->id])
        ->and(app(AlertData::class)->accountAds($acc->id))->toHaveCount(4);
});

it('sums metrics inside the window with live days and first spend day', function () {
    $ad = W::ad(W::account());
    W::spend($ad, W::day(-1), 100, ['purchases' => 1, 'purchase_value' => 300, 'msg_conversations' => 4]);
    W::spend($ad, W::day(-3), 50);
    W::spend($ad, W::day(-2), 0);
    W::spend($ad, W::day(-9), 999);

    $s = app(AlertData::class)->sums([$ad->id], W::day(-3), W::day(-1))[$ad->id];

    expect($s)->toBe(['spend' => 150.0, 'purchases' => 1.0, 'purchase_value' => 300.0, 'msg_conversations' => 4, 'days_live' => 2, 'first_day' => W::day(-3)])
        ->and(AlertData::emptySums()['spend'])->toBe(0.0);
});

it('counts real orders by Cairo day: no cancelled, no returned, no order after midnight Cairo', function () {
    $ad = W::ad(W::account());
    W::order($ad, W::day(-1).' 12:00', 900);
    W::order($ad, W::day(-1).' 13:00', 500, ['status' => 'cancelled']);
    W::order($ad, W::day(-1).' 14:00', 700, ['shipment_status' => 'returned']);
    W::order($ad, W::day(0).' 01:30', 800);

    expect(app(AlertData::class)->realOrders([$ad->id], W::day(-1), W::day(-1)))->toBe([$ad->id => ['count' => 1, 'net' => 900.0]]);
});

it('counts terminal and refused shipments per ad', function () {
    $ad = W::ad(W::account());
    W::order($ad, W::day(-3).' 12:00', 900, ['shipment_status' => 'delivered']);
    W::order($ad, W::day(-3).' 12:00', 900, ['shipment_status' => 'returned']);
    W::order($ad, W::day(-3).' 12:00', 900, ['shipment_status' => 'in_transit']);

    expect(app(AlertData::class)->shipmentOutcomes([$ad->id], W::day(-30), W::day(-1)))->toBe([$ad->id => ['terminal' => 2, 'refused' => 1]]);
});

it('links ads to products with the material stock rule', function () {
    $ad = W::ad(W::account());
    $out = W::product([0, 0]);
    $in = W::product([4]);
    $m1 = W::link($ad, $out);
    W::link($ad, $in);

    $links = collect(app(AlertData::class)->productLinks([$ad->id])[$ad->id])->keyBy('product_id');

    expect($links[$out->id])->toMatchArray(['material_id' => $m1->id, 'stock' => 'out'])
        ->and($links[$in->id]['stock'])->toBe('in')
        ->and(app(AlertData::class)->products([$out->id])[$out->id]->variants)->toHaveCount(2);
});

it('blends daily totals with the control row and reads the last ok sync', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    W::spend($ad, W::day(-1), 100);
    AdAccountDaily::query()->create(['ad_account_id' => $acc->id, 'date' => W::day(-1), 'spend' => 130, 'purchases' => 0, 'purchase_value' => 0, 'impressions' => 0]);
    W::spend($ad, W::day(0), 40);
    AdsSyncRun::factory()->create(['ad_account_id' => $acc->id, 'status' => 'failed', 'finished_at' => now()]);

    expect(app(AlertData::class)->dailyTotals($acc->id, W::day(-1), W::day(0)))->toBe([W::day(-1) => 130.0, W::day(0) => 40.0])
        ->and(app(AlertData::class)->lastOkSyncAt($acc->id)?->diffInMinutes(now(), true))->toEqual(15);
});

it('counts a conversation once, on the day of its first referral by the ad', function () {
    $ad = W::ad(W::account(), 'MESSAGES');
    $first = W::referral($ad, W::day(-5).' 10:00');
    W::referral($ad, W::day(-1).' 10:00', $first->conversation);
    W::referral($ad, W::day(-1).' 11:00');

    expect(app(AlertData::class)->cohortChats([(string) $ad->external_id], W::day(-2), W::day(-1)))->toBe([(string) $ad->external_id => 1]);
});

it('measures minutes to the first user or bot reply after each referral', function () {
    $ad = W::ad(W::account(), 'MESSAGES');
    $r1 = W::referral($ad, W::day(-1).' 12:00');
    $r2 = W::referral($ad, W::day(-1).' 12:00');
    Message::factory()->create(['conversation_id' => $r1->conversation_id, 'direction' => 'out', 'sender_type' => 'bot', 'created_at' => CarbonImmutable::parse(W::day(-1).' 12:30', 'Africa/Cairo')->utc()]);
    Message::factory()->create(['conversation_id' => $r2->conversation_id, 'direction' => 'out', 'sender_type' => 'system', 'created_at' => CarbonImmutable::parse(W::day(-1).' 12:05', 'Africa/Cairo')->utc()]);

    expect(app(AlertData::class)->firstReplyWaits([(string) $ad->external_id], W::day(-1), W::day(-1)))->toEqualCanonicalizing([30.0, null]);
});
