<?php

use App\Ads\Buyers\AssignmentService;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Ads\Reports\BuyerScorecard;
use App\Ads\Reports\RunningCreatives;
use App\Ads\Reports\TopAccounts;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\BuyerTarget;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\MediaBuyer;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

function rptRange(string $from = '2026-09-01', string $to = '2026-09-30', array $extra = []): AdsFilter
{
    return new AdsFilter(CarbonImmutable::parse($from), CarbonImmutable::parse($to), ...$extra);
}

function rptMetric(Ad $ad, string $date, array $values = []): AdDailyMetric
{
    return AdDailyMetric::factory()->create(array_merge([
        'ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => $date,
        'spend' => 0, 'impressions' => 0, 'clicks' => 0, 'reach' => 0, 'purchases' => 0, 'purchase_value' => 0,
    ], $values));
}

/** Sep 1–30 at 100/day spend, 300/day value, 1 purchase/day. */
function rptSeptember(Ad $ad): void
{
    foreach (CarbonPeriod::create('2026-09-01', '2026-09-30') as $d) {
        rptMetric($ad, $d->toDateString(), ['spend' => 100, 'purchase_value' => 300, 'purchases' => 1, 'impressions' => 1000, 'clicks' => 10]);
    }
}

/**
 * acc1 (meta): Ahmed Sep 1–15, Mostafa from Sep 16. acc2 (meta): Mostafa from Sep 1. acc3 (tiktok): unassigned.
 *
 * @return array{acc1:AdAccount, acc2:AdAccount, acc3:AdAccount, ahmed:MediaBuyer, mostafa:MediaBuyer, ad1:Ad, ad2:Ad, ad3:Ad}
 */
function rptWorld(): array
{
    $svc = app(AssignmentService::class);
    $acc1 = AdAccount::factory()->create(['name' => 'Le Voile 1']);
    $acc2 = AdAccount::factory()->create(['name' => 'Le Voile 2']);
    $acc3 = AdAccount::factory()->tiktok()->create(['name' => 'TikTok LV']);
    $ahmed = MediaBuyer::factory()->create(['name' => 'Ahmed Gamal']);
    $mostafa = MediaBuyer::factory()->create(['name' => 'Mostafa']);
    $svc->assign($acc1, $ahmed, CarbonImmutable::parse('2026-09-01'));
    $svc->assign($acc1, $mostafa, CarbonImmutable::parse('2026-09-16'));
    $svc->assign($acc2, $mostafa, CarbonImmutable::parse('2026-09-01'));

    return [
        'acc1' => $acc1, 'acc2' => $acc2, 'acc3' => $acc3, 'ahmed' => $ahmed, 'mostafa' => $mostafa,
        'ad1' => Ad::factory()->for($acc1, 'account')->create(['external_id' => '9001']),
        'ad2' => Ad::factory()->for($acc2, 'account')->create(['external_id' => '9002']),
        'ad3' => Ad::factory()->for($acc3, 'account')->create(['external_id' => '9003']),
    ];
}

function rptOrder(array $attrs): Order
{
    return Order::factory()->create(array_merge([
        'customer_id' => Customer::factory()->create()->id,
        'status' => OrderStatus::Confirmed,
    ], $attrs));
}

it('computes overview totals with tax and roas on pre-tax spend', function () {
    $ad = Ad::factory()->create();
    rptMetric($ad, '2026-09-10', ['spend' => 1000, 'purchase_value' => 5000, 'purchases' => 10, 'impressions' => 100000, 'clicks' => 2000, 'reach' => 40000]);
    rptMetric($ad, '2026-08-31', ['spend' => 999, 'purchase_value' => 1]); // outside the range

    $o = app(AdsOverview::class)->build(rptRange());
    $t = $o['totals'];

    expect($t['spend'])->toBe(1000.0)->and($t['spend_tax'])->toBe(1140.0)
        ->and($t['purchase_value'])->toBe(5000.0)->and($t['roas'])->toBe(5.0)
        ->and($t['purchases'])->toBe(10.0)->and($t['cpa'])->toBe(100.0)
        ->and($t['impressions'])->toBe(100000)->and($t['clicks'])->toBe(2000)->and($t['reach'])->toBe(40000)
        ->and($t['ctr'])->toBe(0.02)
        ->and($t['cpm'])->toBe(10.0)   // 1000 / 100000 * 1000
        ->and($t['cpc'])->toBe(0.5)    // 1000 / 2000
        ->and($t['real_orders'])->toBe(0)->and($t['real_revenue'])->toBe(0.0)->and($t['real_roas'])->toBe(0.0)
        ->and($t['conversations'])->toBe(0)->and($t['conversations_ordered'])->toBe(0);

    expect(array_keys($t))->toEqualCanonicalizing(['spend', 'spend_tax', 'purchase_value', 'roas', 'purchases', 'cpa', 'impressions', 'clicks', 'ctr', 'reach', 'cpm', 'cpc', 'real_orders', 'real_revenue', 'real_roas', 'conversations', 'conversations_ordered']);
    expect($o['daily'])->toHaveCount(30)
        ->and($o['daily'][9])->toMatchArray(['date' => '2026-09-10', 'spend' => 1000.0, 'spend_tax' => 1140.0, 'roas' => 5.0, 'ctr' => 0.02])
        ->and($o['daily'][10])->toMatchArray(['date' => '2026-09-11', 'spend' => 0.0, 'roas' => null, 'ctr' => null, 'cpm' => null, 'cpc' => null]);
    expect(array_keys($o['daily'][0]))->toEqualCanonicalizing(['date', 'spend', 'spend_tax', 'purchase_value', 'roas', 'purchases', 'impressions', 'clicks', 'ctr', 'cpm', 'cpc', 'reach', 'real_orders', 'real_revenue']);
    expect($o['platforms'])->toBe([['platform' => 'meta', 'spend' => 1000.0, 'spend_tax' => 1140.0, 'purchase_value' => 5000.0, 'roas' => 5.0, 'accounts' => 1]])
        ->and($o['currency'])->toBe('EGP')->and($o['tax_rate'])->toBe(0.14);
});

it('returns null roas when there is no spend', function () {
    $o = app(AdsOverview::class)->build(rptRange());
    expect($o['totals']['spend'])->toBe(0.0)->and($o['totals']['roas'])->toBeNull()->and($o['totals']['cpa'])->toBeNull()
        ->and($o['totals']['ctr'])->toBeNull()->and($o['totals']['cpm'])->toBeNull()->and($o['totals']['cpc'])->toBeNull()
        ->and($o['totals']['real_roas'])->toBeNull()->and($o['platforms'])->toBe([]);

    $ad = Ad::factory()->create();
    rptMetric($ad, '2026-09-03', ['purchase_value' => 200, 'purchases' => 1]); // value without spend
    $t = app(AdsOverview::class)->build(rptRange())['totals'];
    expect($t['roas'])->toBeNull()->and($t['purchase_value'])->toBe(200.0)->and($t['cpa'])->toBe(0.0);
});

it('builds buyer scorecards across an owner change with budgets prorated', function () {
    $w = rptWorld();
    rptSeptember($w['ad1']);                                                        // Ahmed 1500 (Sep 1–15), Mostafa 1500 (Sep 16–30)
    rptMetric($w['ad2'], '2026-09-20', ['spend' => 500, 'purchase_value' => 1000]); // Mostafa +500
    rptMetric($w['ad3'], '2026-09-05', ['spend' => 5000, 'purchase_value' => 100]); // unassigned, biggest spend but listed last
    BuyerTarget::factory()->create(['media_buyer_id' => $w['ahmed']->id, 'month' => '2026-09-01', 'budget' => 6000, 'target_roas' => 2.5]);
    BuyerTarget::factory()->create(['media_buyer_id' => $w['mostafa']->id, 'month' => '2026-09-01', 'budget' => 3000, 'target_roas' => 4]);
    BuyerTarget::factory()->create(['media_buyer_id' => $w['mostafa']->id, 'month' => '2026-10-01', 'budget' => 3100, 'target_roas' => 3.5]);

    $cards = app(BuyerScorecard::class)->build(rptRange());

    expect(array_column($cards, 'name'))->toBe(['Mostafa', 'Ahmed Gamal', 'غير مسند']);
    [$mostafa, $ahmed, $none] = $cards;

    expect($ahmed)->toMatchArray([
        'buyer_id' => $w['ahmed']->id, 'spend' => 1500.0, 'spend_tax' => 1710.0, 'purchase_value' => 4500.0,
        'roas' => 3.0, 'purchases' => 15.0, 'cpa' => 100.0, 'ctr' => 0.01,
        'budget' => 6000.0, 'budget_used_pct' => 25.0, 'target_roas' => 2.5, 'roas_vs_target' => 1.2, // 3.0 / 2.5
    ])->and($ahmed['accounts'])->toBe([['id' => $w['acc1']->id, 'name' => 'Le Voile 1', 'platform' => 'meta']]);

    // Mostafa: 1500 + 500 = 2000 spend, 4500 + 1000 = 5500 value
    expect($mostafa)->toMatchArray([
        'spend' => 2000.0, 'purchase_value' => 5500.0, 'roas' => 2.75, 'purchases' => 15.0,
        'budget' => 3000.0, 'budget_used_pct' => 66.67, 'target_roas' => 4.0, 'roas_vs_target' => 0.69, // 2.75 / 4
    ])->and(array_column($mostafa['accounts'], 'id'))->toEqualCanonicalizing([$w['acc1']->id, $w['acc2']->id]);

    expect($none)->toMatchArray(['buyer_id' => null, 'spend' => 5000.0, 'budget' => null, 'budget_used_pct' => null, 'target_roas' => null, 'roas_vs_target' => null])
        ->and($none['accounts'])->toBe([['id' => $w['acc3']->id, 'name' => 'TikTok LV', 'platform' => 'tiktok']]);

    // Sep 16 – Oct 15: 3000 × 15/30 + 3100 × 15/31 = 1500 + 1500; target ROAS of the month of `to` (October)
    $span = collect(app(BuyerScorecard::class)->build(rptRange('2026-09-16', '2026-10-15')))->firstWhere('buyer_id', $w['mostafa']->id);
    expect($span['budget'])->toBe(3000.0)->and($span['target_roas'])->toBe(3.5)->and($span['spend'])->toBe(2000.0); // 1500 (acc1) + 500 (acc2)

    // the buyer filter keeps only that buyer
    expect(array_column(app(BuyerScorecard::class)->build(rptRange(extra: ['buyerId' => $w['ahmed']->id])), 'buyer_id'))->toBe([$w['ahmed']->id]);

    $detail = app(BuyerScorecard::class)->detail($w['ahmed'], rptRange());
    expect($detail['spend'])->toBe(1500.0)->and($detail['daily'])->toHaveCount(30)
        ->and($detail['daily'][0]['spend'])->toBe(100.0)->and($detail['daily'][20]['spend'])->toBe(0.0)
        ->and($detail['top_ads'])->toHaveCount(1)->and($detail['top_ads'][0]['spend'])->toBe(1500.0)
        ->and($detail['campaigns'])->toBeArray();
});

it('computes roas against target from the raw sums, not the rounded roas', function () {
    $w = rptWorld();
    rptMetric($w['ad2'], '2026-09-10', ['spend' => 300, 'purchase_value' => 1000]); // roas 3.333 -> shown 3.33
    BuyerTarget::factory()->create(['media_buyer_id' => $w['mostafa']->id, 'month' => '2026-09-01', 'budget' => 1000, 'target_roas' => 0.5]);

    $card = collect(app(BuyerScorecard::class)->build(rptRange()))->firstWhere('buyer_id', $w['mostafa']->id);
    expect($card['roas'])->toBe(3.33)->and($card['roas_vs_target'])->toBe(6.67); // 3.33 / 0.5 would read 6.66
});

it('restricts a buyer user to their own rows', function () {
    $w = rptWorld();
    rptSeptember($w['ad1']);
    rptMetric($w['ad2'], '2026-09-20', ['spend' => 500]);
    rptMetric($w['ad3'], '2026-09-05', ['spend' => 5000]);
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $w['ahmed']->update(['user_id' => $user->id]);
    $req = Request::create('/ads', 'GET', ['from' => '2026-09-01', 'to' => '2026-09-30', 'buyer' => $w['mostafa']->id]);

    $f = AdsFilter::fromRequest($req, $user);
    expect($f->restrictBuyerId)->toBe($w['ahmed']->id)->and($f->accountIds)->toBe([$w['acc1']->id])
        ->and($f->buyerId)->toBeNull(); // a buyer cannot pick another buyer
    expect(app(AdsOverview::class)->build($f)['totals']['spend'])->toBe(1500.0)
        ->and(array_column(app(BuyerScorecard::class)->build($f), 'buyer_id'))->toBe([$w['ahmed']->id])
        ->and(app(TopAccounts::class)->build($f)[0]['spend'])->toBe(1500.0);

    // real orders and inbox conversations follow the same isolation (owner on the day)
    $cust = Customer::factory()->create();
    rptOrder(['ad_id' => $w['ad1']->id, 'placed_at' => '2026-09-10 10:00', 'total' => 500, 'customer_id' => $cust->id]); // Ahmed
    rptOrder(['ad_id' => $w['ad1']->id, 'placed_at' => '2026-09-20 10:00', 'total' => 700]);                            // Mostafa (acc1 after Sep 15)
    rptOrder(['ad_id' => $w['ad2']->id, 'placed_at' => '2026-09-12 10:00', 'total' => 900]);                            // Mostafa
    rptOrder(['ad_id' => $w['ad3']->id, 'placed_at' => '2026-09-12 10:00', 'total' => 300]);                            // unassigned
    Conversation::factory()->create(['customer_id' => $cust->id, 'ad_id' => '9001', 'ad_attributed_at' => '2026-09-05 10:00']); // Ahmed, ordered
    Conversation::factory()->create(['customer_id' => Customer::factory()->create()->id, 'ad_id' => '9001', 'ad_attributed_at' => '2026-09-18 10:00']); // Mostafa
    Conversation::factory()->create(['customer_id' => Customer::factory()->create()->id, 'ad_id' => '9002', 'ad_attributed_at' => '2026-09-06 10:00']); // Mostafa
    Conversation::factory()->create(['customer_id' => Customer::factory()->create()->id, 'ad_id' => '9003', 'ad_attributed_at' => '2026-09-06 10:00']); // unassigned

    $mine = app(AdsOverview::class)->build($f)['totals'];
    expect($mine)->toMatchArray(['real_orders' => 1, 'real_revenue' => 500.0, 'conversations' => 1, 'conversations_ordered' => 1])
        ->and(app(BuyerScorecard::class)->build($f)[0])->toMatchArray(['real_orders' => 1, 'real_revenue' => 500.0, 'conversations' => 1, 'conversations_ordered' => 1]);

    $unlinked = User::factory()->create(['role' => UserRole::MediaBuyer]);
    $content = User::factory()->create(['role' => UserRole::Content]);
    foreach ([$unlinked, $content] as $u) {
        $uf = AdsFilter::fromRequest($req, $u);
        expect(app(AdsOverview::class)->build($uf)['totals'])->toMatchArray(['real_orders' => 0, 'real_revenue' => 0.0, 'conversations' => 0, 'conversations_ordered' => 0]);
        expect(app(AdsOverview::class)->build($uf)['totals']['spend'])->toBe(0.0)
            ->and(app(BuyerScorecard::class)->build($uf))->toBe([])
            ->and(app(RunningCreatives::class)->build($uf, [])['meta']['total'])->toBe(0)
            ->and(app(TopAccounts::class)->build($uf))->toBe([]);
    }

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $af = AdsFilter::fromRequest(Request::create('/ads', 'GET', ['from' => '2026-09-01', 'to' => '2026-09-30']), $admin);
    expect($af->accountIds)->toBeNull()->and($af->restrictBuyerId)->toBeNull()
        ->and(app(AdsOverview::class)->build($af)['totals']['spend'])->toBe(8500.0); // 3000 + 500 + 5000

    $af = AdsFilter::fromRequest(Request::create('/ads', 'GET', ['from' => '2026-09-01', 'to' => '2026-09-30', 'buyer' => $w['mostafa']->id, 'platform' => 'meta']), $admin);
    expect(app(AdsOverview::class)->build($af)['totals']['spend'])->toBe(2000.0);

    // defaults: the last 30 days ending today in Cairo
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-02 22:30', 'UTC')); // Oct 3, 01:30 in Cairo
    $df = AdsFilter::fromRequest(Request::create('/ads'), $admin);
    expect($df->from->toDateString())->toBe('2026-09-04')->and($df->to->toDateString())->toBe('2026-10-03');
    CarbonImmutable::setTestNow();
});

it('counts real orders net of refunds and excludes cancelled', function () {
    $w = rptWorld();
    rptSeptember($w['ad1']); // 3000 spend: Ahmed 1500, Mostafa 1500
    $camp = AdCampaign::factory()->for($w['acc1'], 'account')->create();

    $o1 = rptOrder(['ad_id' => $w['ad1']->id, 'placed_at' => '2026-09-10 10:00', 'total' => 1000]);       // Ahmed
    Refund::factory()->create(['order_id' => $o1->id, 'amount' => 150]);
    Refund::factory()->create(['order_id' => $o1->id, 'amount' => 50]);                                    // net 800
    rptOrder(['ad_id' => $w['ad1']->id, 'placed_at' => '2026-09-15 22:30', 'total' => 500]);              // Sep 16 01:30 Cairo → Mostafa
    rptOrder(['ad_id' => $w['ad1']->id, 'placed_at' => '2026-09-12 10:00', 'total' => 900, 'status' => OrderStatus::Cancelled]);
    rptOrder(['ad_id' => null, 'ad_campaign_id' => $camp->id, 'placed_at' => '2026-09-05 09:00', 'total' => 300]); // campaign only → Ahmed
    rptOrder(['ad_id' => $w['ad1']->id, 'placed_at' => '2026-10-01 10:00', 'total' => 700]);              // after the range
    rptOrder(['ad_id' => $w['ad3']->id, 'placed_at' => '2026-09-20 10:00', 'total' => 100]);              // unassigned tiktok
    rptOrder(['ad_id' => null, 'placed_at' => '2026-09-20 10:00', 'total' => 999]);                       // not from an ad

    $o = app(AdsOverview::class)->build(rptRange());
    expect($o['totals']['real_orders'])->toBe(4)
        ->and($o['totals']['real_revenue'])->toBe(1700.0)   // 800 + 500 + 300 + 100
        ->and($o['totals']['real_roas'])->toBe(0.57)        // 1700 / 3000
        ->and($o['daily'][15])->toMatchArray(['date' => '2026-09-16', 'real_orders' => 1, 'real_revenue' => 500.0]);

    $cards = collect(app(BuyerScorecard::class)->build(rptRange()))->keyBy(fn ($c) => $c['buyer_id'] ?? 0);
    expect($cards[$w['ahmed']->id])->toMatchArray(['real_orders' => 2, 'real_revenue' => 1100.0, 'real_roas' => 0.73]) // 1100 / 1500
        ->and($cards[$w['mostafa']->id])->toMatchArray(['real_orders' => 1, 'real_revenue' => 500.0])
        ->and($cards[0])->toMatchArray(['real_orders' => 1, 'real_revenue' => 100.0, 'real_roas' => null]);

    // a buyer filter attributes by the owner on the order's day
    expect(app(AdsOverview::class)->build(rptRange(extra: ['buyerId' => $w['mostafa']->id]))['totals']['real_orders'])->toBe(1)
        ->and(app(AdsOverview::class)->build(rptRange(extra: ['platform' => 'tiktok']))['totals']['real_revenue'])->toBe(100.0);

    $row = collect(app(RunningCreatives::class)->build(rptRange(), [])['data'])->firstWhere('id', $w['ad1']->id);
    expect($row['real_orders'])->toBe(2); // o1 and o2 (the campaign-only order is not credited to an ad)
});

it('drops courier-returned orders from real orders, revenue and conversations that ordered', function () {
    $w = rptWorld();
    rptSeptember($w['ad1']);
    $kept = rptOrder(['ad_id' => $w['ad1']->id, 'placed_at' => '2026-09-10 10:00', 'total' => 1000, 'shipment_status' => 'delivered']);
    Refund::factory()->create(['order_id' => $kept->id, 'amount' => 100]);                                 // refunds still subtracted
    rptOrder(['ad_id' => $w['ad1']->id, 'placed_at' => '2026-09-11 10:00', 'total' => 400]);                // no shipment yet
    $returned = rptOrder(['ad_id' => $w['ad1']->id, 'placed_at' => '2026-09-12 10:00', 'total' => 2000, 'shipment_status' => 'returned']);

    $t = app(AdsOverview::class)->build(rptRange())['totals'];
    expect($t['real_orders'])->toBe(2)->and($t['real_revenue'])->toBe(1300.0); // 900 + 400

    Conversation::factory()->create(['customer_id' => $returned->customer_id, 'ad_id' => '9001', 'ad_attributed_at' => '2026-09-11 09:00']);
    Conversation::factory()->create(['customer_id' => $kept->customer_id, 'ad_id' => '9001', 'ad_attributed_at' => '2026-09-09 09:00']);
    $t = app(AdsOverview::class)->build(rptRange())['totals'];
    expect($t['conversations'])->toBe(2)->and($t['conversations_ordered'])->toBe(1);
});

it('counts inbox conversations from a buyer\'s ads and those that ordered', function () {
    $w = rptWorld();
    [$a, $b, $c, $d] = Customer::factory()->count(4)->create()->all();
    $conv = fn (Customer $cu, string $ad, string $at) => Conversation::factory()->create(['customer_id' => $cu->id, 'ad_id' => $ad, 'ad_attributed_at' => $at]);

    $conv($a, '9001', '2026-09-05 10:00');  // Ahmed; A orders Sep 6
    $conv($a, '9001', '2026-09-07 10:00');  // Ahmed; same customer
    $conv($b, '9001', '2026-09-20 10:00');  // Mostafa; B ordered before the touch only
    $conv($c, '9001', '2026-09-21 10:00');  // Mostafa; C's order is cancelled
    $conv($d, '9002', '2026-09-25 10:00');  // Mostafa (acc2); D orders Sep 26
    $conv($d, '7777', '2026-09-25 10:00');  // unknown ad
    $conv($b, '9001', '2026-08-30 10:00');  // before the range
    rptOrder(['customer_id' => $a->id, 'placed_at' => '2026-09-06 12:00']);
    rptOrder(['customer_id' => $b->id, 'placed_at' => '2026-09-19 12:00']);
    rptOrder(['customer_id' => $c->id, 'placed_at' => '2026-09-22 12:00', 'status' => OrderStatus::Cancelled]);
    rptOrder(['customer_id' => $d->id, 'placed_at' => '2026-09-26 12:00']);

    $t = app(AdsOverview::class)->build(rptRange())['totals'];
    expect($t['conversations'])->toBe(5)->and($t['conversations_ordered'])->toBe(2); // customers A and D

    $cards = collect(app(BuyerScorecard::class)->build(rptRange()))->keyBy('buyer_id');
    expect($cards[$w['ahmed']->id])->toMatchArray(['conversations' => 2, 'conversations_ordered' => 1])
        ->and($cards[$w['mostafa']->id])->toMatchArray(['conversations' => 3, 'conversations_ordered' => 1]);
});

it('paginates running creatives and counts active/inactive', function () {
    $acc = AdAccount::factory()->create(['name' => 'Main']);
    $camp = AdCampaign::factory()->for($acc, 'account')->create(['name' => 'Sales']);
    foreach (range(1, 12) as $i) {
        $ad = Ad::factory()->for($acc, 'account')->create([
            'name' => "Ad {$i}", 'ad_campaign_id' => $camp->id, 'effective_status' => $i <= 3 ? 'PAUSED' : 'ACTIVE',
        ]);
        rptMetric($ad, '2026-09-10', ['spend' => $i * 10, 'purchase_value' => $i * 20, 'impressions' => 1000, 'clicks' => $i]);
    }
    Ad::factory()->for($acc, 'account')->create(['name' => 'Never ran']); // no metrics in range → not listed
    $svc = app(RunningCreatives::class);

    $r = $svc->build(rptRange(), ['per_page' => 10, 'page' => 2, 'sort' => 'spend']);
    expect($r['meta'])->toBe(['total' => 12, 'per_page' => 10, 'current_page' => 2, 'last_page' => 2])
        ->and(array_column($r['data'], 'spend'))->toBe([20.0, 10.0])
        ->and($r['counts'])->toBe(['all' => 12, 'active' => 9, 'inactive' => 3])
        ->and($r['accounts'])->toBe([['id' => $acc->id, 'name' => 'Main', 'count' => 12]])
        ->and($r['totals']['spend'])->toBe(780.0)   // 10 + 20 + … + 120
        ->and($r['totals']['roas'])->toBe(2.0);
    expect($r['data'][0])->toMatchArray(['name' => 'Ad 2', 'campaign' => 'Sales', 'account' => 'Main', 'platform' => 'meta', 'roas' => 2.0, 'ctr' => 0.002, 'real_orders' => 0])
        ->and($r['data'][0])->not->toHaveKey('preview_html');

    $active = $svc->build(rptRange(), ['status' => 'active', 'per_page' => 7]);
    expect($active['meta']['total'])->toBe(9)->and($active['meta']['per_page'])->toBe(25)
        ->and($active['counts'])->toBe(['all' => 12, 'active' => 9, 'inactive' => 3])
        ->and($active['totals']['spend'])->toBe(720.0); // 780 − 10 − 20 − 30

    expect($svc->build(rptRange(), ['status' => 'inactive', 'sort' => 'clicks'])['data'][0]['name'])->toBe('Ad 3')
        ->and($svc->build(rptRange(), ['q' => 'Ad 1'])['meta']['total'])->toBe(4) // Ad 1, 10, 11, 12
        ->and($svc->build(rptRange(), ['account' => $acc->id + 999])['meta']['total'])->toBe(0);

    $detail = $svc->detail(Ad::where('name', 'Ad 5')->first(), rptRange());
    expect($detail['spend'])->toBe(50.0)->and($detail)->toHaveKey('preview_html');
});

it('lists top accounts by spend with their current buyer', function () {
    $w = rptWorld();
    rptSeptember($w['ad1']);
    rptMetric($w['ad3'], '2026-09-05', ['spend' => 5000, 'purchase_value' => 100, 'purchases' => 1]);

    $rows = app(TopAccounts::class)->build(rptRange());
    expect(array_column($rows, 'id'))->toBe([$w['acc3']->id, $w['acc1']->id])
        ->and($rows[0])->toMatchArray(['platform' => 'tiktok', 'buyer' => null, 'spend' => 5000.0, 'spend_tax' => 5700.0, 'roas' => 0.02])
        ->and($rows[1])->toMatchArray(['name' => 'Le Voile 1', 'buyer' => 'Mostafa', 'spend' => 3000.0, 'purchases' => 30.0, 'roas' => 3.0]);

    // an admin's buyer filter: Ahmed's half of acc1, labelled Ahmed (not the holder on the last day)
    $ahmed = app(TopAccounts::class)->build(rptRange(extra: ['buyerId' => $w['ahmed']->id]));
    expect($ahmed)->toHaveCount(1)->and($ahmed[0])->toMatchArray(['id' => $w['acc1']->id, 'buyer' => 'Ahmed Gamal', 'spend' => 1500.0]);
});
