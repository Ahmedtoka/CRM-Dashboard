<?php

use App\Ads\AdsSettings;
use App\Ads\Reports\AdsFilter;
use App\Ads\Reports\WinnerScorer;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdDailyMetric;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/** Daily rows for an ad: same spend/value/purchases on every day of [from,to]. */
function winDays(Ad $ad, string $from, string $to, float $spend, float $value, float $purchases = 1): void
{
    foreach (CarbonPeriod::create($from, $to) as $d) {
        AdDailyMetric::factory()->create([
            'ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => $d->toDateString(),
            'spend' => $spend, 'purchase_value' => $value, 'purchases' => $purchases, 'impressions' => 1000, 'clicks' => 20, 'reach' => 500,
        ]);
    }
}

/**
 * Account 1 over Sep 1–30 (window = 30 days, the last 7 days are Sep 24–30):
 *   A  Sep 21–30  spend 100/day, value 500/day, a sale every day   → spend 1000, revenue 5000
 *   B  Sep 21–24  spend 100/day, value 0                           → spend 400 (below the 500 gate)
 *   C  Sep 1–10   spend 120/day, two days with a 50 sale            → spend 1200, revenue 100, PAUSED
 *   D  Sep 15–19  spend 120/day, value 200/day, a sale every day    → spend 600, revenue 1000
 * Account-wide: spend 3200, revenue 6100 → avgROAS = 1.90625; prior = K(1) × 500 → avg × prior = 953.125.
 * Account 2: E  Sep 28–29  spend 300/day → 600 spend but only 2 active days (and its own account average).
 */
function winWorld(): array
{
    $acc = AdAccount::factory()->create();
    $acc2 = AdAccount::factory()->create();
    $cp = activeCampaignId($acc);
    $a = Ad::factory()->for($acc, 'account')->create(['ad_campaign_id' => $cp, 'name' => 'A', 'created_time' => '2026-09-20 10:00']);
    $b = Ad::factory()->for($acc, 'account')->create(['ad_campaign_id' => $cp, 'name' => 'B']);
    $c = Ad::factory()->for($acc, 'account')->create(['ad_campaign_id' => $cp, 'name' => 'C', 'effective_status' => 'PAUSED', 'created_time' => '2026-08-30 10:00']);
    $d = Ad::factory()->for($acc, 'account')->create(['ad_campaign_id' => $cp, 'name' => 'D', 'created_time' => '2026-09-14 10:00']);
    $e = Ad::factory()->for($acc2, 'account')->create(['ad_campaign_id' => activeCampaignId($acc2), 'name' => 'E']);
    winDays($a, '2026-09-21', '2026-09-30', 100, 500);
    winDays($b, '2026-09-21', '2026-09-24', 100, 0, 0);
    winDays($c, '2026-09-01', '2026-09-02', 120, 50);
    winDays($c, '2026-09-03', '2026-09-10', 120, 0, 0);
    winDays($d, '2026-09-15', '2026-09-19', 120, 200);
    winDays($e, '2026-09-28', '2026-09-29', 300, 0, 0);

    return compact('a', 'b', 'c', 'd', 'e');
}

function winFilter(string $from = '2026-09-01', string $to = '2026-09-30'): AdsFilter
{
    return new AdsFilter(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
}

it('scores winners with smoothing, the 7-day blend and the gate', function () {
    winWorld();
    $rows = app(WinnerScorer::class)->build(winFilter());

    // B (spend 400 < 500) and E (2 active days < 3) are excluded by the gate
    expect(array_column(array_column($rows, 'ad'), 'name'))->toBe(['A', 'D', 'C']);
    [$a, $d, $c] = $rows;

    // A: smoothed = (5000 + 953.125) / (1000 + 500) = 3.96875
    //    last 7 days (Sep 24–30): spend 700, revenue 3500 → (3500 + 953.125) / (700 + 500) = 3.7109375
    //    blended = (3.96875 + 1.25 × 3.7109375) / 2.25 = 3.8255208
    //    score = min(3.8255 / 2, 1) × 70 + (10 / 10) × 30 = 100
    expect($a)->toMatchArray([
        'score' => 100, 'tier' => 'winner', 'smoothed_roas' => 3.97, 'blended_roas' => 3.83, 'roas' => 5.0,
        'spend' => 1000.0, 'revenue' => 5000.0, 'orders' => 10.0, 'cpa' => 100.0, 'ctr' => 0.02, 'cvr' => 0.05,
        'active_days' => 10, 'days_with_sales' => 10, 'recommendation' => 'زوّد الميزانية 20–30٪ بالتدريج',
    ]);

    // D: smoothed = (1000 + 953.125) / (600 + 500) = 1.7755682 → promising (≥ 1.3, < 2)
    //    no spend in the last 7 days → blended = smoothed
    //    score = round(1.7755682 / 2 × 70 + 1 × 30) = round(92.14) = 92
    expect($d)->toMatchArray([
        'score' => 92, 'tier' => 'promising', 'smoothed_roas' => 1.78, 'blended_roas' => 1.78, 'roas' => 1.67,
        'spend' => 600.0, 'revenue' => 1000.0, 'active_days' => 5, 'days_with_sales' => 5,
        'recommendation' => 'سيبه يجمع داتا ٣ أيام كمان',
    ]);

    // C: smoothed = (100 + 953.125) / (1200 + 500) = 0.6194853 → loser (< 0.8 with spend 1200 ≥ 1000)
    //    consistency = 2 / 10 → score = round(0.6194853 / 2 × 70 + 0.2 × 30) = round(27.68) = 28
    expect($c)->toMatchArray([
        'score' => 28, 'tier' => 'loser', 'smoothed_roas' => 0.62, 'blended_roas' => 0.62, 'roas' => 0.08,
        'spend' => 1200.0, 'revenue' => 100.0, 'orders' => 2.0, 'cpa' => 600.0, 'active_days' => 10, 'days_with_sales' => 2,
        'recommendation' => 'وقّفه أو غيّر الكرييتف',
    ])->and($c['ad']['effective_status'])->toBe('PAUSED');
});

it('filters winners by status, sorts, and reads thresholds from settings', function () {
    winWorld();
    $svc = app(WinnerScorer::class);

    expect(array_column(array_column($svc->build(winFilter(), 'active'), 'ad'), 'name'))->toBe(['A', 'D'])
        ->and(array_column(array_column($svc->build(winFilter(), 'inactive'), 'ad'), 'name'))->toBe(['C'])
        ->and(array_column(array_column($svc->build(winFilter(), 'all', 'spend'), 'ad'), 'name'))->toBe(['C', 'A', 'D'])
        ->and(array_column(array_column($svc->build(winFilter(), 'all', 'date'), 'ad'), 'name'))->toBe(['A', 'D', 'C']);

    // a lower spend gate lets B in: smoothed = (0 + 953.125) / (400 + 500) = 1.059 → neutral
    app(AdsSettings::class)->set('winner_thresholds', ['min_spend' => 300]);
    $b = collect($svc->build(winFilter()))->firstWhere('ad.name', 'B');
    expect($b['tier'])->toBe('neutral')->and($b['smoothed_roas'])->toBe(1.06)->and($b['recommendation'])->toBe('راقبه');
});

it('clamps the window to at least 7 days anchored at the end', function () {
    winWorld();
    // Sep 28–30 widens to Sep 24–30: A has 7 active days there (spend 700 ≥ 500), nobody else passes the gate
    $rows = app(WinnerScorer::class)->build(winFilter('2026-09-28', '2026-09-30'));
    expect(array_column(array_column($rows, 'ad'), 'name'))->toBe(['A'])
        ->and($rows[0]['spend'])->toBe(700.0)->and($rows[0]['active_days'])->toBe(7);
});

it('serves the old winners questions from the explorer cards (S2: winning / losing health)', function () {
    winWorld();
    app(AdsSettings::class)->set('winner_thresholds', ['min_spend' => 300]); // B joins as neutral
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $names = fn ($res) => collect($res->viewData('page')['props']['result']['data'])->pluck('name')->sort()->values()->all();
    $this->withoutVite()->actingAs($admin);

    $this->get('/ads/winners?from=2026-09-01&to=2026-09-30')
        ->assertRedirect('/ads/explorer?from=2026-09-01&to=2026-09-30&view=cards&status=all&health=winning&sort=-roas');
    $this->get('/ads/winners?from=2026-09-01&to=2026-09-30&tier=loser')
        ->assertRedirect('/ads/explorer?from=2026-09-01&to=2026-09-30&view=cards&status=all&health=losing&sort=-roas');

    $base = '/ads/explorer?from=2026-09-01&to=2026-09-30&view=cards&status=all';
    expect($names($this->get($base.'&health=winning')->assertOk()))->toBe(['A'])
        ->and($names($this->get($base.'&health=losing')))->toBe(['C'])
        ->and($names($this->get($base)))->toBe(['A', 'B', 'C', 'D', 'E']); // the explorer lists every ad that ran, gate or not
});

it('pages the explorer cards by per_page (the old winners paging)', function () {
    $acc = AdAccount::factory()->create();
    foreach (range(1, 30) as $i) {
        winDays(Ad::factory()->for($acc, 'account')->create(['ad_campaign_id' => activeCampaignId($acc), 'name' => 'Ad '.$i]), '2026-09-21', '2026-09-30', 100, 100 * (1 + $i % 5));
    }
    $this->withoutVite()->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    $base = '/ads/explorer?from=2026-09-01&to=2026-09-30&view=cards&status=all&per_page=25';
    $p1 = $this->get($base)->viewData('page')['props'];
    $p2 = $this->get($base.'&page=2')->viewData('page')['props'];
    $p9 = $this->get($base.'&page=9')->viewData('page')['props'];

    expect($p1['result']['data'])->toHaveCount(25)->and($p1['result']['meta'])->toBe(['total' => 30, 'per_page' => 25, 'current_page' => 1, 'last_page' => 2])
        ->and($p2['result']['data'])->toHaveCount(5)->and($p2['filters']['page'])->toBe(2)
        ->and($p9['result']['meta']['current_page'])->toBe(2)
        ->and(array_intersect(array_column($p1['result']['data'], 'id'), array_column($p2['result']['data'], 'id')))->toBe([]);
});
