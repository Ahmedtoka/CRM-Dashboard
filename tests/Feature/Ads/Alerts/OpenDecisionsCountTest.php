<?php

use App\Ads\Decisions\DecisionCounter;
use App\Models\AdsAlert;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AlertWorld as W;

beforeEach(function () {
    W::freeze();
    Cache::flush();
});

/** One unfolded stop suggestion (A), one suggestion folded into its ad's alert card (B), one alert-only card (C). */
function odWorld(): array
{
    $acc = W::account();
    $a = W::ad($acc);
    W::spendDays($a, 5, 300);
    $b = W::ad($acc);
    W::spendDays($b, 5, 300);
    AdsAlert::factory()->create(['ad_id' => $b->id, 'money_at_risk_per_day' => 300]);
    $c = W::ad($acc);
    AdsAlert::factory()->create(['ad_id' => $c->id, 'money_at_risk_per_day' => 100]);

    return ['acc' => $acc, 'a' => $a, 'b' => $b, 'c' => $c];
}

it('counts approvals, open alert cards and unfolded stop suggestions once (final fix 8)', function () {
    $w = odWorld();
    $owner = W::authority();

    $b = app(DecisionCounter::class)->breakdown($owner);

    expect($b['alert_cards'])->toBe(2)
        ->and(array_map(fn ($s) => (int) $s['ad_id'], $b['suggestions']))->toBe([$w['a']->id])
        ->and($b['total'])->toBe($b['approvals'] + 1 + 2);
});

it('shows the same open-decisions number on the badge, the Today block, the digest and the Decisions tab (final fix 8)', function () {
    odWorld();
    $owner = W::authority();
    $expected = app(DecisionCounter::class)->breakdown($owner)['total'];
    Cache::flush();

    $this->withoutVite()->actingAs($owner)->get('/ads')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('today.decisions.total', $expected));
    expect(DecisionCounter::cached($owner))->toBe($expected);

    $digest = $this->actingAs($owner)->getJson('/ads/alerts/digest')->assertOk()->json();
    expect($digest['open']['count'])->toBe($expected);

    $this->withoutVite()->actingAs($owner)->get('/ads/decisions')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('counts.open', $expected)->where('adsDecisions', $expected));
});

it('puts the top open alert cards in the Today decisions block and counts them (final fix 3)', function () {
    $w = odWorld();

    $this->withoutVite()->actingAs(W::authority())->get('/ads')->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->has('today.decisions.alerts', 2)
            ->where('today.decisions.alerts.0.key', 'ad:'.$w['b']->id)
            ->where('today.decisions.alerts_total', 2)
            ->where('today.decisions.suggestions', fn ($rows) => collect($rows)->pluck('ad_id')->map(fn ($id) => (int) $id)->all() === [$w['a']->id]));
});

it('scopes the Today alert cards to the buyer', function () {
    $w = odWorld();
    $buyer = W::buyer(W::account());

    $this->withoutVite()->actingAs($buyer)->get('/ads')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('today.decisions.alerts', 0)->where('today.decisions.alerts_total', 0));
});
