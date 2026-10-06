<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Rules\ChatPriceShare;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

it('flags an ad whose chats close on the price', function () {
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    W::spendDays($ad, 3, 200);
    W::fakeChats([$ad->id => ['chats' => 40, 'reasons' => ['price' => 15]]]);

    $f = app(ChatPriceShare::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)->and($f[0]->severity)->toBe('medium')->and($f[0]->action)->toBe('edit_ad')
        ->and($f[0]->params)->toBe(['share' => 38, 'chats' => 40])->and($f[0]->moneyAtRiskPerDay)->toBe(75.0);
});

it('stays quiet at or under 35 percent or under twenty chats', function (array $row) {
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    W::fakeChats([$ad->id => $row]);

    expect(app(ChatPriceShare::class)->evaluate(RuleContext::for($acc)))->toBe([]);
})->with([[['chats' => 40, 'reasons' => ['price' => 14]]], [['chats' => 19, 'reasons' => ['price' => 19]]]]);
