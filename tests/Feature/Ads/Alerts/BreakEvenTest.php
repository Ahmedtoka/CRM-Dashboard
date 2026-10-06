<?php

use App\Ads\Alerts\BreakEven;
use App\Ads\Alerts\RuleSettings;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('uses the 2.5 default until a margin is entered', function () {
    $acc = W::account();

    expect(app(BreakEven::class)->forAccount($acc->id))->toBe(['floor' => 2.5, 'is_default' => true]);
});

it('computes the floor from the inputs plus the measured refusal rate and AOV', function () {
    $acc = W::account();
    $ad = W::ad($acc, 'OUTCOME_SALES', ['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
    foreach (range(1, 20) as $i) {
        W::order($ad, W::day(-40).' 12:00', 1200, ['shipment_status' => $i <= 4 ? 'returned' : 'delivered']);
    }
    app(RuleSettings::class)->saveInputs(W::authority(), $acc->id, ['margin_pct' => 55, 'shipping_subsidy' => 60, 'return_cost' => 70]);

    $e = app(BreakEven::class)->explain($acc->id);

    expect($e['aov'])->toBe(1200.0)->and($e['aov_source'])->toBe('account')
        ->and($e['refusal_rate'])->toBe(0.2)->and($e['refusal_source'])->toBe('account')
        ->and($e['max_cpa'])->toBe(408.77)->and($e['unprofitable'])->toBeFalse()
        ->and(app(BreakEven::class)->forAccount($acc->id))->toBe(['floor' => 2.94, 'is_default' => false]);
});

it('falls back to store-wide numbers when the account has too few orders', function () {
    $acc = W::account();
    $otherAd = W::ad(W::account());
    foreach (range(1, 25) as $i) {
        W::order($otherAd, W::day(-10).' 12:00', 1000, ['shipment_status' => $i <= 5 ? 'returned' : 'delivered']);
    }

    expect(app(BreakEven::class)->measured($acc->id))
        ->toBe(['aov' => 1000.0, 'aov_source' => 'store', 'refusal_rate' => 0.2, 'refusal_source' => 'store']);
});

it('assumes a 20 % refusal and has no AOV without any order', function () {
    expect(app(BreakEven::class)->measured(W::account()->id))
        ->toBe(['aov' => null, 'aov_source' => 'none', 'refusal_rate' => 0.2, 'refusal_source' => 'assumed']);
});

it('caps an unprofitable setup at the maximum floor', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    foreach (range(1, 20) as $i) {
        W::order($ad, W::day(-5).' 12:00', 1200, ['shipment_status' => 'delivered']);
    }
    app(RuleSettings::class)->saveInputs(W::authority(), null, ['margin_pct' => 4, 'shipping_subsidy' => 60]);

    $e = app(BreakEven::class)->explain($acc->id);

    expect($e['floor'])->toBe(10.0)->and($e['is_default'])->toBeFalse()->and($e['unprofitable'])->toBeTrue();
});
