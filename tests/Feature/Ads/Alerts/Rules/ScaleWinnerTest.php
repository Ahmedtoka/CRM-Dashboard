<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Rules\ScaleWinner;
use App\Ads\Alerts\RuleSettings;
use App\Models\AdsAlert;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

function swSales(int $ordersPerDay)
{
    $acc = W::account();
    $ad = W::ad($acc);
    W::spendDays($ad, 14, 100, -2, ['purchases' => 2, 'purchase_value' => 500]);
    foreach (range(2, 15) as $d) {
        foreach (range(1, $ordersPerDay) as $i) {
            W::order($ad, W::day(-$d).' 12:00', 250);
        }
    }

    return [$acc, $ad];
}

it('suggests scaling a sales ad that wins on the worse of Meta and real orders', function () {
    [$acc, $ad] = swSales(2);

    $f = app(ScaleWinner::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)->and($f[0]->kind)->toBe('recommendation')->and($f[0]->severity)->toBe('info')->and($f[0]->action)->toBe('none')
        ->and($f[0]->params)->toMatchArray(['days' => 7, 'roas' => 5.0, 'floor' => 2.5])->and($f[0]->evidence['attribution'])->toBe('worst_of');
});

it('does not trust Meta alone: real orders at half the value stop the suggestion', function () {
    [$acc] = swSales(1);

    expect(app(ScaleWinner::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});

it('never suggests scaling an ad with an open high alert', function () {
    [$acc, $ad] = swSales(2);
    AdsAlert::factory()->create(['ad_id' => $ad->id, 'severity' => 'high']);

    expect(app(ScaleWinner::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});

it('suggests scaling a messages ad with cheap chat orders', function () {
    app(RuleSettings::class)->saveInputs(W::authority(), null, ['target_cpo' => 300]);
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    W::spendDays($ad, 14, 200, -3);
    W::fakeChats([$ad->id => ['chats' => 40, 'orders' => 12]]);

    $f = app(ScaleWinner::class)->evaluate(RuleContext::for($acc));

    expect($f[0]->sentenceKey)->toBe('scale_winner_msg')->and($f[0]->params)->toMatchArray(['cpo' => 233, 'target' => 300, 'orders' => 12]);
});

it('does not suggest scaling a messages ad Meta barely delivers', function () {
    app(RuleSettings::class)->saveInputs(W::authority(), null, ['target_cpo' => 300]);
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    $sibling = W::ad($acc, 'MESSAGES', [], $ad->adSet);
    W::spendDays($ad, 14, 200, -3);
    W::spendDays($sibling, 14, 5000, -3);
    W::fakeChats([$ad->id => ['chats' => 40, 'orders' => 12]]);

    expect(app(ScaleWinner::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});
