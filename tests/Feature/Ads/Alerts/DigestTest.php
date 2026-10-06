<?php

use App\Enums\UserRole;
use App\Models\AdsAlert;
use App\Models\MediaBuyer;
use App\Models\User;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

/** @return array<string, mixed> */
function dgWorld(): array
{
    $acc = W::account();
    $buyer = W::buyer($acc);
    $buyerId = MediaBuyer::query()->where('user_id', $buyer->id)->value('id');
    $ad = W::ad($acc);
    W::spend($ad, W::day(-1), 1000);
    W::order($ad, W::day(-1).' 12:00', 900);
    W::order($ad, W::day(-1).' 13:00', 900);

    return [
        'acc' => $acc, 'buyer' => $buyer,
        'big' => AdsAlert::factory()->create(['ad_id' => $ad->id, 'buyer_id' => $buyerId, 'money_at_risk_per_day' => 900, 'first_fired_at' => now()->subDays(3)]),
        'small' => AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id, 'buyer_id' => $buyerId, 'money_at_risk_per_day' => 100]),
        'dismissed' => AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id, 'buyer_id' => $buyerId, 'state' => 'dismissed', 'dismiss_reason' => 'wrong_numbers', 'closed_at' => now()->subDay()]),
        'foreign' => AdsAlert::factory()->create(['ad_id' => W::ad(W::account())->id, 'money_at_risk_per_day' => 5000]),
    ];
}

it('gives the owner yesterday numbers, the money leaks and the buyers table', function () {
    $w = dgWorld();

    $d = $this->actingAs(W::authority())->getJson('/ads/alerts/digest')->assertOk()->json();

    expect($d['variant'])->toBe('owner')->and($d['shadow'])->toBeTrue()
        ->and($d['yesterday'])->toMatchArray(['spend' => 1000, 'orders' => 2, 'revenue' => 1800, 'roas' => 1.8, 'floor' => 2.5, 'floor_default' => true])
        ->and($d['open'])->toMatchArray(['count' => 3, 'high' => 3])
        ->and(array_column($d['top'], 'alert_id'))->toBe([$w['foreign']->id, $w['big']->id, $w['small']->id])
        ->and($d['top'][1]['age_days'])->toBe(3)
        ->and(collect($d['buyers'])->firstWhere('name', $w['buyer']->name))->toMatchArray(['open' => 2, 'dismissed' => 1, 'dismissed_wrong_numbers' => 1, 'silent_spend' => 900]);
});

it('gives a buyer only their own list and numbers', function () {
    $w = dgWorld();

    $d = $this->actingAs($w['buyer'])->getJson('/ads/alerts/digest')->assertOk()->json();

    expect($d['variant'])->toBe('buyer')->and($d['buyers'])->toBe([])
        ->and(array_column($d['top'], 'alert_id'))->toBe([$w['big']->id, $w['small']->id])
        ->and($d['yesterday']['spend'])->toEqual(1000);
});

it('gives a supervisor the owner variant without approvals', function () {
    dgWorld();

    $d = $this->actingAs(User::factory()->create(['role' => UserRole::Supervisor]))->getJson('/ads/alerts/digest')->assertOk()->json();

    expect($d['variant'])->toBe('owner')->and($d['approvals'])->toBe(0);
});

it('is closed to content and moderators', function (UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/ads/alerts/digest')->assertForbidden();
})->with([UserRole::Content, UserRole::Moderator]);

it('puts the usual spend on the same tax basis as yesterday spend with tax (final fix 6)', function () {
    config(['crm.ads.tax_rate' => 0.14]);
    $acc = W::account();
    $ad = W::ad($acc);
    W::spendDays($ad, 14, 1000, -2); // the 14 days before yesterday
    W::spend($ad, W::day(-1), 1000);

    $d = $this->actingAs(W::authority())->getJson('/ads/alerts/digest')->assertOk()->json();

    expect($d['yesterday']['spend_tax'])->toEqual(1140)->and($d['yesterday']['usual_spend'])->toEqual(1140);
});
