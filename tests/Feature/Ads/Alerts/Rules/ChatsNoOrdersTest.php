<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\RuleSettings;
use App\Ads\Alerts\Rules\ChatsNoOrders;
use Tests\Support\AlertWorld as W;

beforeEach(function () {
    W::freeze();
    app(RuleSettings::class)->saveInputs(W::authority(), null, ['target_cpo' => 300]);
});

it('asks to stop a messages ad with many chats and no order', function () {
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    W::spendDays($ad, 14, 80, -3);
    W::fakeChats([$ad->id => ['chats' => 20, 'orders' => 0]]);

    $f = app(ChatsNoOrders::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)->and($f[0]->severity)->toBe('high')->and($f[0]->action)->toBe('stop')
        ->and($f[0]->params)->toBe(['chats' => 20, 'days' => 14, 'k' => 3.7, 'spend' => 1120.0])
        ->and($f[0]->moneyAtRiskPerDay)->toBe(80.0);
});

it('stays quiet with an order, too few chats or no target', function (array $row, ?int $target) {
    if ($target === null) {
        app(RuleSettings::class)->saveInputs(W::authority(), null, ['target_cpo' => null]);
    }
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    W::spendDays($ad, 14, 80, -3);
    W::fakeChats([$ad->id => $row]);

    expect(app(ChatsNoOrders::class)->evaluate(RuleContext::for($acc)))->toBe([]);
})->with([
    [['chats' => 20, 'orders' => 1], 300],
    [['chats' => 14, 'orders' => 0], 300],
    [['chats' => 20, 'orders' => 0], null],
]);

it('stays quiet when the whole account stopped converting chats (inbox problem)', function () {
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    W::spendDays($ad, 14, 80, -3);
    W::fakeChats([$ad->id => ['chats' => 20, 'orders' => 0]], fn (array $ids, string $from) => $from === W::day(-30) ? [$ad->id => ['chats' => 20, 'orders' => 8]] : null);

    expect(app(ChatsNoOrders::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});
