<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Rules\OutOfStock;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('asks to stop a sales ad whose product ran out, keyed by product', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    W::spendDays($ad, 3, 300);
    $p = W::product([0, 0]);
    W::link($ad, $p);

    $f = app(OutOfStock::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)
        ->and($f[0]->severity)->toBe('critical')->and($f[0]->action)->toBe('stop')->and($f[0]->productId)->toBe($p->id)
        ->and($f[0]->moneyAtRiskPerDay)->toBe(300.0)->and($f[0]->sentenceKey)->toBe('out_of_stock')
        ->and($f[0]->params)->toBe(['product' => 'Abaya Noor'])->and($f[0]->evidence['inventory'])->toBe(0);
});

it('asks a messages ad to check stock instead (R-10)', function () {
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    W::link($ad, W::product([0]));

    $f = app(OutOfStock::class)->evaluate(RuleContext::for($acc));

    expect($f[0]->severity)->toBe('high')->and($f[0]->action)->toBe('check_stock')->and($f[0]->sentenceKey)->toBe('out_of_stock_msg');
});

it('treats a multi-product creative as check stock', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    W::link($ad, W::product([0]));
    W::link($ad, W::product([6], 900, ['title' => 'Kaftan']));

    $f = app(OutOfStock::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)->and($f[0]->action)->toBe('check_stock')->and($f[0]->evidence['multi_product'])->toBeTrue();
});

it('stays quiet for in-stock, manually in-stock, paused and parent-paused ads', function () {
    $acc = W::account();
    W::link(W::ad($acc), W::product([3]));
    W::link(W::ad($acc), W::product([0]), ['stock_override' => true]);
    W::link(W::ad($acc, 'OUTCOME_SALES', ['status' => 'PAUSED', 'effective_status' => 'PAUSED']), W::product([0]));
    W::link(W::ad($acc, 'OUTCOME_SALES', ['effective_status' => 'ADSET_PAUSED']), W::product([0]));

    expect(app(OutOfStock::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});
