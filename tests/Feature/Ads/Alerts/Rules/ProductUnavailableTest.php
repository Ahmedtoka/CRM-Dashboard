<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Rules\ProductUnavailable;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('asks to stop an ad sending people to a draft or deleted product', function (string $how, string $status) {
    $acc = W::account();
    $ad = W::ad($acc);
    W::spendDays($ad, 3, 250);
    $p = W::product([5], 900, $how === 'draft' ? ['status' => 'draft'] : []);
    W::link($ad, $p);
    if ($how === 'deleted') {
        $p->delete();
    }

    $f = app(ProductUnavailable::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)->and($f[0]->severity)->toBe('critical')->and($f[0]->action)->toBe('stop')
        ->and($f[0]->params)->toBe(['product' => 'Abaya Noor', 'status' => $status])->and($f[0]->moneyAtRiskPerDay)->toBe(250.0);
})->with([['draft', 'draft'], ['deleted', 'deleted']]);

it('stays quiet for an active product', function () {
    $acc = W::account();
    W::link(W::ad($acc), W::product([5]));

    expect(app(ProductUnavailable::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});
