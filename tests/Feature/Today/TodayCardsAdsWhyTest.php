<?php

use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\AdsOverview;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdCampaign;
use App\Models\AdDailyMetric;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\User;
use App\Today\TodayCards;
use App\Today\TodayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Africa/Cairo')));

function twAd(AdAccount $acc, string $name): Ad
{
    $camp = AdCampaign::factory()->create(['ad_account_id' => $acc->id, 'status' => 'ACTIVE']);

    return Ad::factory()->for($acc, 'account')->create(['ad_campaign_id' => $camp->id, 'name' => $name]);
}

function twSpend(Ad $ad, string $date, float $spend): void
{
    AdDailyMetric::factory()->create(['ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => $date, 'spend' => $spend, 'purchase_value' => 0, 'purchases' => 0, 'impressions' => 1000, 'clicks' => 10, 'reach' => 800]);
}

/** One S3 outcome row (column names per the S3 migration). */
function twOutcome(Conversation $c, string $outcome): void
{
    DB::table('conversation_outcomes')->insert([
        'conversation_id' => $c->id, 'episode_key' => 'e'.$c->id.$outcome, 'outcome' => $outcome, 'note' => null,
        'set_by_id' => null, 'source' => 'agent', 'set_at' => now()->subHour(), 'ended_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('builds the ads card over yesterday and today: totals as AdsOverview, best and losing ad', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $acc = AdAccount::factory()->meta()->create();
    $winner = twAd($acc, 'اسدال كتان');
    $loser = twAd($acc, 'عباية سادة');
    twSpend($winner, '2026-10-05', 300);
    twSpend($winner, '2026-10-06', 200);
    twSpend($loser, '2026-10-06', 900);
    Order::factory()->count(2)->create(['status' => OrderStatus::Confirmed, 'ad_id' => $winner->id, 'placed_at' => now()->subHour(), 'total' => 1000]);

    $card = app(TodayCards::class)->ads(TodayWindow::for('today'), $admin);
    $totals = app(AdsOverview::class)->totals(new AdsFilter(CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-06')));

    expect($card)->toMatchArray([
        'from' => '2026-10-05', 'to' => '2026-10-06', 'currency' => 'EGP',
        'spend' => (float) $totals['spend'], 'real_orders' => $totals['real_orders'], 'real_roas' => $totals['real_roas'], 'meta_roas' => $totals['roas'],
    ])->and($card['real_orders'])->toBe(2)
        ->and($card['cost_per_order'])->toBe(round($totals['spend'] / 2, 2))
        ->and($card['best'])->toBe(['id' => $winner->id, 'name' => 'اسدال كتان', 'orders' => 2])
        ->and($card['loser'])->toBe(['id' => $loser->id, 'name' => 'عباية سادة', 'spend' => 900.0])
        ->and($card['links']['best'])->toBe("/ads/explorer?from=2026-10-05&to=2026-10-06&ad={$winner->id}");
});

it('reads the ads card on yesterday alone in yesterday mode', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $acc = AdAccount::factory()->meta()->create();
    $ad = twAd($acc, 'x');
    twSpend($ad, '2026-10-05', 300);
    twSpend($ad, '2026-10-06', 200);

    $card = app(TodayCards::class)->ads(TodayWindow::for('yesterday'), $admin);

    expect([$card['from'], $card['to']])->toBe(['2026-10-05', '2026-10-05'])->and($card['best'])->toBeNull();
});

it('explains why chats did not buy from the recorded outcomes', function () {
    $acc = AdAccount::factory()->meta()->create();
    $ad = twAd($acc, 'اسدال كتان');
    $c = fn (array $a = []) => Conversation::factory()->create($a + ['created_at' => now()->subHours(2)]);
    twOutcome($c(), 'price');
    twOutcome($c(), 'price');
    twOutcome($c(['ad_id' => $ad->external_id, 'source' => 'ad', 'ad_attributed_at' => now()->subHours(2)]), 'size_out'); // ChatFunnel reads the touch and the episode end
    twOutcome($c(), 'ordered');
    twOutcome($c(), 'unknown');
    twOutcome($c(['is_test' => true]), 'price');

    $why = app(TodayCards::class)->why(TodayWindow::for('today'));

    expect($why['total'])->toBe(3)->and($why['ordered'])->toBe(1)
        ->and($why['reasons'])->toBe([
            ['key' => 'price', 'count' => 2, 'share' => 0.67],
            ['key' => 'size_out', 'count' => 1, 'share' => 0.33],
        ])
        ->and($why['top_size_out'])->toMatchArray(['id' => $ad->id, 'name' => 'اسدال كتان', 'count' => 1])
        ->and($why['links']['reasons'])->toBe('/ads/numbers?from=2026-10-06&to=2026-10-06#funnel');
});
