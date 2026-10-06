<?php

use App\Ads\Reports\AdsFilter;
use App\Inbox\Outcomes\ChatFunnel;
use App\Models\Ad;
use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\Order;
use App\Models\QueueEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-08 12:00', 'UTC')));

function s3Touch(Ad $ad, array $attrs = [], ?Carbon $at = null): Conversation
{
    $at ??= now()->subDays(2);
    $c = Conversation::factory()->create($attrs);
    DB::table('conversation_ad_referrals')->insert(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'ad_external_id' => $ad->external_id, 'referred_at' => $at]);

    return $c;
}

function s3Outcome(Conversation $c, string $outcome, ?Carbon $at = null): void
{
    ConversationOutcome::create(['conversation_id' => $c->id, 'episode_key' => 'k'.$c->id.$outcome, 'outcome' => $outcome, 'source' => 'agent', 'set_at' => $at ?? now()->subDay(), 'ended_at' => $at ?? now()->subDay()]);
}

it('counts the funnel stages and the why-not-bought reasons per ad', function () {
    $ad = Ad::factory()->create();
    $other = Ad::factory()->create();
    $plain = s3Touch($ad);
    $handed = s3Touch($ad, ['handover_at' => now()->subDays(2)->addHour()]);
    $queued = s3Touch($ad);
    QueueEntry::factory()->create(['conversation_id' => $queued->id, 'enqueued_at' => now()->subDays(2)->addHour(), 'status' => 'closed']);
    $buyer = s3Touch($ad, ['handover_at' => now()->subDays(2)->addHour()]);
    Order::factory()->create(['conversation_id' => $buyer->id, 'customer_id' => $buyer->customer_id, 'status' => 'confirmed', 'placed_at' => now()->subDay(), 'delivered_at' => now()]);
    $returner = s3Touch($ad);
    Order::factory()->create(['customer_id' => $returner->customer_id, 'status' => 'confirmed', 'placed_at' => now()->subDay(), 'shipment_status' => 'returned']);
    $unpaid = s3Touch($ad);
    Order::factory()->create(['conversation_id' => $unpaid->id, 'customer_id' => $unpaid->customer_id, 'status' => 'awaiting_payment', 'placed_at' => now()->subDay()]);
    s3Touch($ad, ['is_test' => true]);
    s3Outcome($plain, 'price');
    s3Outcome($handed, 'size_out');
    s3Outcome($queued, 'price');
    s3Outcome($buyer, 'ordered');
    s3Outcome($unpaid, 'service');

    $rows = app(ChatFunnel::class)->forAds([$ad->id, $other->id], CarbonImmutable::now()->subDays(7), CarbonImmutable::now());

    expect($rows[$ad->id])->toBe([
        'chats' => 6, 'to_agent' => 3, 'orders' => 2, 'delivered' => 1, 'returned' => 1,
        'reasons' => ['price' => 2, 'size_out' => 1],
    ])->and($rows[$other->id])->toBe(ChatFunnel::empty());
});

it('ignores touches outside the range and outcomes before the touch', function () {
    $ad = Ad::factory()->create();
    s3Touch($ad, [], now()->subDays(20));
    $c = s3Touch($ad);
    s3Outcome($c, 'price', now()->subDays(3)); // before the touch

    $row = app(ChatFunnel::class)->forAds([$ad->id], CarbonImmutable::now()->subDays(7), CarbonImmutable::now())[$ad->id];

    expect($row['chats'])->toBe(1)->and($row['reasons'])->toBe([]);
});

it('counts a pre-referral conversation from the conversation ad columns once', function () {
    $ad = Ad::factory()->create();
    Conversation::factory()->create(['ad_id' => $ad->external_id, 'ad_attributed_at' => now()->subDays(2)]);

    expect(app(ChatFunnel::class)->forAds([$ad->id], CarbonImmutable::now()->subDays(7), CarbonImmutable::now())[$ad->id]['chats'])->toBe(1);
});

it('sums rows', function () {
    $a = ['chats' => 3, 'to_agent' => 1, 'orders' => 1, 'delivered' => 1, 'returned' => 0, 'reasons' => ['price' => 2]];
    $b = ['chats' => 2, 'to_agent' => 2, 'orders' => 0, 'delivered' => 0, 'returned' => 0, 'reasons' => ['price' => 1, 'shipping' => 1]];

    expect(ChatFunnel::total([$a, $b]))->toBe(['chats' => 5, 'to_agent' => 3, 'orders' => 1, 'delivered' => 1, 'returned' => 0, 'reasons' => ['price' => 3, 'shipping' => 1]]);
});

// Review round 1 (minor): an order delivered and then returned counts as returned only; orders found
// through the conversation and through the customer both count.
it('counts a delivered-then-returned order as returned only', function () {
    $ad = Ad::factory()->create();
    $c = s3Touch($ad);
    Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'confirmed', 'placed_at' => now()->subDay(), 'delivered_at' => now()->subHours(5), 'shipment_status' => 'returned']);
    $viaCustomer = s3Touch($ad);
    Order::factory()->create(['conversation_id' => null, 'customer_id' => $viaCustomer->customer_id, 'status' => 'confirmed', 'placed_at' => now()->subDay(), 'delivered_at' => now()]);

    $row = app(ChatFunnel::class)->forAds([$ad->id], CarbonImmutable::now()->subDays(7), CarbonImmutable::now())[$ad->id];

    expect($row['orders'])->toBe(2)->and($row['delivered'])->toBe(1)->and($row['returned'])->toBe(1);
});

it('final review B2: page totals count a multi-touch chat once and a customer-matched order once', function () {
    $a = Ad::factory()->create();
    $b = Ad::factory()->for($a->account, 'account')->create();
    $c = s3Touch($a, ['handover_at' => now()->subDays(2)->addHours(3)]);
    DB::table('conversation_ad_referrals')->insert(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'ad_external_id' => $b->external_id, 'referred_at' => now()->subDays(2)->addHour()]);
    // a second chat of the same customer, touched by b too
    $c2 = Conversation::factory()->create(['customer_id' => $c->customer_id]);
    DB::table('conversation_ad_referrals')->insert(['conversation_id' => $c2->id, 'customer_id' => $c->customer_id, 'ad_external_id' => $b->external_id, 'referred_at' => now()->subDays(2)->addHours(2)]);
    Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'confirmed', 'placed_at' => now()->subDay(), 'delivered_at' => now()]);
    s3Outcome($c2, 'price');

    $range = [CarbonImmutable::now()->subDays(7), CarbonImmutable::now()];
    $perAd = app(ChatFunnel::class)->forAds([$a->id, $b->id], ...$range);
    expect(ChatFunnel::total($perAd)['orders'])->toBe(3); // per-ad rows still credit each ad

    $filter = new AdsFilter(CarbonImmutable::now()->subDays(7), CarbonImmutable::now(), accountIds: [$a->ad_account_id]);
    expect(app(ChatFunnel::class)->forFilter($filter))->toBe([
        'chats' => 2, 'to_agent' => 1, 'orders' => 1, 'delivered' => 1, 'returned' => 0, 'reasons' => ['price' => 1],
    ]);
});
