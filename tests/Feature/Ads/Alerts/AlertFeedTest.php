<?php

use App\Ads\Alerts\AlertFeed;
use App\Enums\UserRole;
use App\Models\AdsAlert;
use App\Models\User;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('makes one card per ad with its reasons stacked, highest severity first', function () {
    $acc = W::account();
    $ad = W::ad($acc, 'OUTCOME_SALES', ['name' => 'Eid Abaya V2']);
    AdsAlert::factory()->create(['ad_id' => $ad->id, 'rule_id' => 'all.price_mismatch', 'severity' => 'medium', 'money_at_risk_per_day' => 50]);
    AdsAlert::factory()->create(['ad_id' => $ad->id, 'rule_id' => 'all.spend_no_result', 'severity' => 'high', 'money_at_risk_per_day' => 400]);

    $feed = app(AlertFeed::class)->forUser(W::authority());

    expect($feed['items'])->toHaveCount(1)
        ->and($feed['items'][0])->toMatchArray(['key' => 'ad:'.$ad->id, 'kind' => 'ad', 'severity' => 'high', 'money_at_risk_per_day' => 400.0])
        ->and(array_column($feed['items'][0]['reasons'], 'rule_id'))->toBe(['all.spend_no_result', 'all.price_mismatch'])
        ->and($feed['items'][0]['primary'])->toMatchArray(['verb' => 'stop', 'action' => 'stop'])
        ->and($feed['items'][0]['ad']['name'])->toBe('Eid Abaya V2')
        ->and($feed['meta'])->toMatchArray(['tab' => 'open', 'shadow' => true, 'can_toggle' => true, 'hidden_by_cap' => 0]);
});

it('groups out-of-stock ads per product and sorts by severity then money', function () {
    $acc = W::account();
    $p = W::product([0]);
    foreach ([300, 200] as $money) {
        AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id, 'rule_id' => 'all.out_of_stock', 'severity' => 'critical', 'product_id' => $p->id, 'money_at_risk_per_day' => $money]);
    }
    $cheap = AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id, 'severity' => 'high', 'money_at_risk_per_day' => 50]);
    $dear = AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id, 'severity' => 'high', 'money_at_risk_per_day' => 900]);

    $items = app(AlertFeed::class)->forUser(W::authority())['items'];

    expect(array_column($items, 'key'))->toBe(['product:'.$p->id.':stop', 'ad:'.$dear->ad_id, 'ad:'.$cheap->ad_id])
        ->and($items[0]['ads'])->toHaveCount(2)->and($items[0]['money_at_risk_per_day'])->toBe(500.0);
});

it('never puts a sales Stop and a messages check-stock on one product card (R-10)', function () {
    $acc = W::account();
    $p = W::product([0]);
    $stop = AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id, 'rule_id' => 'all.out_of_stock', 'severity' => 'critical', 'action' => 'stop', 'product_id' => $p->id]);
    $check = AdsAlert::factory()->create(['ad_id' => W::ad($acc, 'MESSAGES')->id, 'rule_id' => 'all.out_of_stock', 'severity' => 'high', 'action' => 'check_stock', 'product_id' => $p->id]);

    $items = collect(app(AlertFeed::class)->forUser(W::authority())['items'])->keyBy('key');

    expect($items->keys()->all())->toBe(['product:'.$p->id.':stop', 'product:'.$p->id.':check_stock'])
        ->and($items['product:'.$p->id.':stop']['primary']['verb'])->toBe('stop')
        ->and($items['product:'.$p->id.':stop']['ads'][0])->toMatchArray(['alert_id' => $stop->id, 'action' => 'stop'])
        ->and($items['product:'.$p->id.':check_stock']['primary']['verb'])->toBe('open_stock')
        ->and($items['product:'.$p->id.':check_stock']['ads'][0])->toMatchArray(['alert_id' => $check->id, 'action' => 'check_stock']);
});

it('caps a buyer at seven open cards and keeps the rest for the digest', function () {
    $acc = W::account();
    $buyer = W::buyer($acc);
    foreach (range(1, 9) as $i) {
        AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id, 'money_at_risk_per_day' => $i * 10]);
    }

    $mine = app(AlertFeed::class)->forUser($buyer);
    $owner = app(AlertFeed::class)->forUser(W::authority());

    expect($mine['items'])->toHaveCount(7)->and($mine['meta']['hidden_by_cap'])->toBe(2)->and($mine['meta']['can_toggle'])->toBeFalse()
        ->and($owner['items'])->toHaveCount(9);
});

it('moves a card to later and back to open through the routes', function () {
    $acc = W::account();
    $buyer = W::buyer($acc);
    $a = AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id]);

    $this->actingAs($buyer)->postJson('/ads/alerts/snooze', ['ids' => [$a->id], 'until' => 'tomorrow'])->assertOk()
        ->assertJsonPath('until', '2026-10-07T09:00:00+03:00');

    $feed = app(AlertFeed::class)->forUser($buyer, 'later');
    expect($a->fresh()->state)->toBe('snoozed')->and($feed['items'])->toHaveCount(1)->and($feed['meta']['counts'])->toBe(['open' => 0, 'later' => 1, 'closed' => 0]);
});

it('needs a reason to disagree and keeps stock dismissals for Ads authority', function () {
    $acc = W::account();
    $buyer = W::buyer($acc);
    $perf = AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id]);
    $stock = AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id, 'rule_id' => 'all.out_of_stock']);

    $this->actingAs($buyer)->postJson('/ads/alerts/dismiss', ['ids' => [$perf->id]])->assertUnprocessable();
    $this->actingAs($buyer)->postJson('/ads/alerts/dismiss', ['ids' => [$stock->id], 'reason' => 'handling_it'])->assertForbidden();
    $this->actingAs($buyer)->postJson('/ads/alerts/dismiss', ['ids' => [$perf->id], 'reason' => 'wrong_numbers', 'note' => 'Meta lags'])->assertOk();

    expect($perf->fresh())->state->toBe('dismissed')->dismiss_reason->toBe('wrong_numbers')
        ->and($stock->fresh()->state)->toBe('open');
});

it('answers 404 for another buyer alert and 403 for content and moderators', function () {
    $acc = W::account();
    $buyer = W::buyer($acc);
    $foreign = AdsAlert::factory()->create(['ad_id' => W::ad(W::account())->id]);

    $this->actingAs($buyer)->postJson('/ads/alerts/seen', ['ids' => [$foreign->id]])->assertNotFound();
    foreach ([UserRole::Content, UserRole::Moderator] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))->postJson('/ads/alerts/seen', ['ids' => [$foreign->id]])->assertForbidden();
    }
    $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]))->postJson('/ads/alerts/seen', ['ids' => [$foreign->id]])->assertOk();
    expect($foreign->fresh()->seen_at)->not->toBeNull();
});

it('snoozes «بكرة» to the next 09:00 Cairo at least an hour away', function (string $now, string $until) {
    W::freeze($now);
    $acc = W::account();
    $buyer = W::buyer($acc);
    $a = AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id]);

    $this->actingAs($buyer)->postJson('/ads/alerts/snooze', ['ids' => [$a->id], 'until' => 'tomorrow'])->assertOk()->assertJsonPath('until', $until);
})->with([
    ['2026-10-06 07:00:00', '2026-10-06T09:00:00+03:00'],
    ['2026-10-06 08:30:00', '2026-10-07T09:00:00+03:00'],
    ['2026-10-06 23:30:00', '2026-10-07T09:00:00+03:00'],
]);
