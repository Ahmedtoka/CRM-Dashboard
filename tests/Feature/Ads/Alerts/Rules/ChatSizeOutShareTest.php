<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Rules\ChatSizeOutShare;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('flags an ad whose chats close on a missing size', function () {
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    W::spendDays($ad, 3, 100);
    W::fakeChats([$ad->id => ['chats' => 40, 'reasons' => ['size_out' => 12, 'price' => 4]]]);

    $f = app(ChatSizeOutShare::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)->and($f[0]->severity)->toBe('medium')->and($f[0]->action)->toBe('check_stock')
        ->and($f[0]->params)->toBe(['share' => 30, 'chats' => 40])->and($f[0]->moneyAtRiskPerDay)->toBe(30.0);
});

it('needs twenty chats and more than a quarter', function (array $row) {
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    W::fakeChats([$ad->id => $row]);

    expect(app(ChatSizeOutShare::class)->evaluate(RuleContext::for($acc)))->toBe([]);
})->with([[['chats' => 19, 'reasons' => ['size_out' => 10]]], [['chats' => 40, 'reasons' => ['size_out' => 10]]]]);
