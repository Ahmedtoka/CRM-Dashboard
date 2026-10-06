<?php

use App\Ads\Decisions\DecisionCounter;
use App\Models\AdsAlert;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

// S2's page already has the tabs open | snoozed | closed | log (`?tab=`); the feed follows them (snoozed = «بعدين»).
it('fills the alerts section for the owner, in shadow mode, and counts the cards as open decisions', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    AdsAlert::factory()->create(['ad_id' => $ad->id]);

    $this->withoutVite()->actingAs(W::authority())->get('/ads/decisions')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Ads/Decisions', false)
            ->has('alerts', 1)->where('alerts.0.key', 'ad:'.$ad->id)
            ->where('alertsMeta.shadow', true)->where('alertsMeta.tab', 'open')
            ->where('counts.open', 1)->where('counts.snoozed', 0)->where('counts.closed', 0));
});

it('scopes the section to the buyer accounts held today', function () {
    $acc = W::account();
    $buyer = W::buyer($acc);
    AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id]);
    AdsAlert::factory()->create(['ad_id' => W::ad(W::account())->id]);

    $this->withoutVite()->actingAs($buyer)->get('/ads/decisions')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('alerts', 1)->where('alertsMeta.can_toggle', false));
});

it('folds a stop suggestion into the card of an ad that already has an alert', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    W::spendDays($ad, 5, 300);
    AdsAlert::factory()->create(['ad_id' => $ad->id]);

    $this->withoutVite()->actingAs(W::authority())->get('/ads/decisions')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('suggestions', fn ($rows) => collect($rows)->every(fn ($r) => (int) $r['ad_id'] !== $ad->id)));
});

it('shows snoozed cards on the snoozed tab and nothing on the log tab', function () {
    $acc = W::account();
    AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id, 'state' => 'snoozed', 'snoozed_until' => now()->addDay()]);

    $this->withoutVite()->actingAs(W::authority())->get('/ads/decisions?tab=snoozed')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('alertsMeta.tab', 'later')->has('alerts', 1)->where('counts.snoozed', 1));
    $this->withoutVite()->actingAs(W::authority())->get('/ads/decisions?tab=log')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('alertsMeta.tab', 'log')->has('alerts', 0)->has('log'));
});

it('counts open alert cards in the nav badge refreshed by the hourly command', function () {
    $acc = W::account();
    $buyer = W::buyer($acc);
    AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id]);

    expect(app(DecisionCounter::class)->refresh($buyer))->toBe(1);
});
