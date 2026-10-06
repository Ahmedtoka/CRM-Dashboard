<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Rules\HighRefusal;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('flags an ad whose orders come back far more than the account average', function () {
    $acc = W::account();
    $bad = W::ad($acc);
    $good = W::ad($acc, 'OUTCOME_SALES', ['status' => 'PAUSED', 'effective_status' => 'PAUSED']);
    W::spendDays($bad, 3, 100);
    foreach (range(1, 10) as $i) {
        W::order($bad, W::day(-10).' 12:00', 900, ['shipment_status' => $i <= 4 ? 'returned' : 'delivered']);
    }
    foreach (range(1, 30) as $i) {
        W::order($good, W::day(-10).' 12:00', 900, ['shipment_status' => 'delivered']);
    }

    $f = app(HighRefusal::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)->and($f[0]->adId)->toBe($bad->id)->and($f[0]->severity)->toBe('medium')->and($f[0]->action)->toBe('view_orders')
        ->and($f[0]->params)->toBe(['share' => 40, 'avg' => 10, 'orders' => 10])->and($f[0]->moneyAtRiskPerDay)->toBe(40.0);
});

it('needs ten finished orders and a share above thirty percent', function (int $orders, int $returned) {
    $acc = W::account();
    $ad = W::ad($acc);
    foreach (range(1, $orders) as $i) {
        W::order($ad, W::day(-10).' 12:00', 900, ['shipment_status' => $i <= $returned ? 'returned' : 'delivered']);
    }

    expect(app(HighRefusal::class)->evaluate(RuleContext::for($acc)))->toBe([]);
})->with([[9, 5], [10, 2]]);
