<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Rules\SizesBroken;
use App\Models\OrderItem;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('flags a product with fewer than half its sizes in stock', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    W::spendDays($ad, 3, 100);
    W::link($ad, W::product([0, 0, 0, 3]));

    $f = app(SizesBroken::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)->and($f[0]->severity)->toBe('high')->and($f[0]->action)->toBe('check_stock')
        ->and($f[0]->params)->toBe(['product' => 'Abaya Noor', 'sizes' => 'S، M، L', 'share' => 25])
        ->and($f[0]->moneyAtRiskPerDay)->toBe(75.0);
});

it('flags when the two best sellers are out even with half the sizes left', function () {
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    $p = W::product([0, 0, 5, 5]);
    W::link($ad, $p);
    $order = W::order(null, W::day(-10).' 12:00', 2000);
    [$s, $m, $l] = $p->variants()->orderBy('id')->take(3)->get()->all();
    OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $s->id, 'qty' => 5]);
    OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $m->id, 'qty' => 4]);
    OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $l->id, 'qty' => 1]);

    $f = app(SizesBroken::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)->and($f[0]->severity)->toBe('medium')->and($f[0]->evidence['top_sellers_out'])->toBeTrue();
});

it('leaves healthy and fully out products alone', function () {
    $acc = W::account();
    W::link(W::ad($acc), W::product([5, 5, 5, 0]));
    W::link(W::ad($acc), W::product([0, 0]));
    W::link(W::ad($acc), W::product([4]));

    expect(app(SizesBroken::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});
