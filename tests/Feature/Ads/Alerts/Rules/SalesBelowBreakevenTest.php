<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Rules\SalesBelowBreakeven;
use App\Ads\Alerts\RuleSettings;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('asks to stop a sales ad returning less than the default floor, and says the floor is a default', function () {
    $acc = W::account();
    W::spendDays(W::ad($acc), 14, 200, -2, ['purchases' => 1, 'purchase_value' => 200]);

    $f = app(SalesBelowBreakeven::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)->and($f[0]->severity)->toBe('high')->and($f[0]->action)->toBe('stop')
        ->and($f[0]->params)->toBe(['roas' => 1.0, 'roas_meta' => 1.0, 'roas_crm' => 0.0, 'floor' => 2.5, 'floor_default' => true])
        ->and($f[0]->moneyAtRiskPerDay)->toBe(120.0)->and($f[0]->evidence['attribution'])->toBe('best_of');
});

it('leaves a winner alone', function () {
    $acc = W::account();
    W::spendDays(W::ad($acc), 14, 200, -2, ['purchases' => 2, 'purchase_value' => 700]);

    expect(app(SalesBelowBreakeven::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});

it('uses the computed floor once the margin is entered', function () {
    $acc = W::account();
    $old = W::ad($acc, 'OUTCOME_SALES', ['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
    foreach (range(1, 20) as $i) {
        W::order($old, W::day(-40).' 12:00', 1200, ['shipment_status' => $i <= 4 ? 'returned' : 'delivered']);
    }
    app(RuleSettings::class)->saveInputs(W::authority(), null, ['margin_pct' => 55, 'shipping_subsidy' => 60, 'return_cost' => 70]);
    W::spendDays(W::ad($acc), 14, 1000, -2, ['purchases' => 2, 'purchase_value' => 2700]);

    $f = app(SalesBelowBreakeven::class)->evaluate(RuleContext::for($acc));

    // ROAS 2.7 is above the 2.5 default but below the computed 2.94: plus-one (37,800 + 1,200) / 14,000 = 2.79 < 2.94
    expect($f)->toHaveCount(1)->and($f[0]->params['floor'])->toBe(2.94)->and($f[0]->params['floor_default'])->toBeFalse()
        ->and($f[0]->moneyAtRiskPerDay)->toBe(81.63);
});

it('protects an ad whose real orders beat Meta (best of)', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    W::spendDays($ad, 14, 200, -2, ['purchase_value' => 200]);
    foreach (range(2, 15) as $d) {
        W::order($ad, W::day(-$d).' 12:00', 600);
    }

    expect(app(SalesBelowBreakeven::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});

it('does not judge an ad in learning or with too little spend', function () {
    $acc = W::account();
    W::spendDays(W::ad($acc), 4, 500, -2, ['purchase_value' => 100]);
    W::spendDays(W::ad($acc), 14, 50, -2, ['purchase_value' => 10]);

    expect(app(SalesBelowBreakeven::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});

it('says once per account that every order loses money, instead of judging each ad', function () {
    $acc = W::account(['name' => 'LV-Main 2']);
    $old = W::ad($acc, 'OUTCOME_SALES', ['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
    foreach (range(1, 20) as $i) {
        W::order($old, W::day(-40).' 12:00', 1200, ['shipment_status' => 'delivered']);
    }
    app(RuleSettings::class)->saveInputs(W::authority(), null, ['margin_pct' => 4, 'shipping_subsidy' => 60]);
    W::spendDays(W::ad($acc), 14, 200, -2, ['purchases' => 1, 'purchase_value' => 200]);
    W::spendDays(W::ad($acc), 14, 100, -2, ['purchases' => 1, 'purchase_value' => 100]);

    $f = app(SalesBelowBreakeven::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)->and($f[0]->entityLevel)->toBe('account')->and($f[0]->entityId)->toBe($acc->id)->and($f[0]->adId)->toBeNull()
        ->and($f[0]->sentenceKey)->toBe('breakeven_unprofitable')->and($f[0]->action)->toBe('open_settings')->and($f[0]->severity)->toBe('high')
        ->and($f[0]->params)->toBe(['account' => 'LV-Main 2'])->and($f[0]->moneyAtRiskPerDay)->toBe(200.0);
});

it('counts a refused order once: the floor already prices refusals in, so returned orders stay in the ad revenue (A5)', function () {
    $acc = W::account();
    $old = W::ad($acc, 'OUTCOME_SALES', ['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
    foreach (range(1, 20) as $i) {
        W::order($old, W::day(-40).' 12:00', 1200, ['shipment_status' => $i <= 4 ? 'returned' : 'delivered']);
    }
    app(RuleSettings::class)->saveInputs(W::authority(), null, ['margin_pct' => 55, 'shipping_subsidy' => 60, 'return_cost' => 70]);
    $ad = W::ad($acc);
    W::spendDays($ad, 14, 1000, -2, ['purchase_value' => 0]);
    foreach (range(0, 39) as $i) {
        W::order($ad, W::day(-2 - $i % 14).' 12:00', 1200, ['shipment_status' => $i < 8 ? 'returned' : 'delivered']);
    }

    // Refusal 12/60 = 20 %, AOV 1,200, tax 14 %: max CPA = (0.8 × (660 − 60) − 0.2 × 70) / 1.14 = 408.76 per PLACED order,
    // floor = 1,200 / 408.76 = 2.94. The ad placed 40 orders on 14,000 spend = 350 per placed order < 408.76: it makes money.
    // Placed revenue 48,000 / 14,000 = 3.43 ≥ 2.94 → no stop. Dropping the 8 returned orders (38,400 → 2.74) would charge
    // the 20 % refusal a second time and stop a profitable ad.
    $ctx = RuleContext::for($acc);
    expect($ctx->breakEven()['floor'])->toBe(2.94)
        ->and(app(SalesBelowBreakeven::class)->evaluate($ctx))->toBe([]);
});
