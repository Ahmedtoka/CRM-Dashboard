<?php

use App\Ads\Alerts\AlertNotifier;
use App\Ads\Alerts\RuleSettings;
use App\Models\AdsAlert;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

function anWorld(): array
{
    $acc = W::account();
    $buyer = W::buyer($acc);
    $otherBuyer = W::buyer(W::account());
    $admin = W::authority();
    $critical = AdsAlert::factory()->create(['ad_id' => W::ad($acc)->id, 'severity' => 'critical', 'money_at_risk_per_day' => 300]);

    return compact('buyer', 'otherBuyer', 'admin', 'critical');
}

it('sends nothing in shadow mode', function () {
    $w = anWorld();

    expect(app(AlertNotifier::class)->critical([$w['critical']->id]))->toBe(0)
        ->and(app(AlertNotifier::class)->digest(CarbonImmutable::now()))->toBe(0)
        ->and(UserNotification::count())->toBe(0);
});

it('groups new critical alerts into one bell item per user who can see them', function () {
    $w = anWorld();
    app(RuleSettings::class)->setNotify($w['admin'], true);

    expect(app(AlertNotifier::class)->critical([$w['critical']->id]))->toBe(2);

    $notes = UserNotification::where('type', 'ads.alerts')->get();
    expect($notes->pluck('user_id')->sort()->values()->all())->toBe(collect([$w['buyer']->id, $w['admin']->id])->sort()->values()->all())
        ->and($notes->first()->data)->toMatchArray(['count' => 1, 'critical' => 1, 'money' => 300, 'link' => '/ads/decisions'])
        ->and($w['critical']->fresh()->notified_at)->not->toBeNull();

    expect(app(AlertNotifier::class)->critical([$w['critical']->id]))->toBe(0);
});

it('sends the 09:00 digest item once per day to users with open items', function () {
    $w = anWorld();
    app(RuleSettings::class)->setNotify($w['admin'], true);
    $nine = W::freeze('2026-10-06 09:00:00');

    expect(app(AlertNotifier::class)->digest($nine))->toBe(2)
        ->and(app(AlertNotifier::class)->digest($nine))->toBe(0)
        ->and(UserNotification::where('type', 'ads.alerts_digest')->where('user_id', $w['otherBuyer']->id)->exists())->toBeFalse();
});
