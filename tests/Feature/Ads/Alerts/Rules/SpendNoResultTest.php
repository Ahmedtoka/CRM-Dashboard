<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\RuleSettings;
use App\Ads\Alerts\Rules\SpendNoResult;
use Tests\Support\AlertWorld as W;

beforeEach(function () {
    W::freeze();
    app(RuleSettings::class)->saveInputs(W::authority(), null, ['target_cpp' => 300]);
});

it('asks to stop a sales ad that spent four times the target with no purchase', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    W::spendDays($ad, 8, 150);

    $f = app(SpendNoResult::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)
        ->and($f[0]->severity)->toBe('high')->and($f[0]->action)->toBe('stop')->and($f[0]->sentenceKey)->toBe('spend_no_result')
        ->and($f[0]->params)->toMatchArray(['spend' => 1200.0, 'k' => 4.0, 'days' => 8, 'result' => 'purchase'])
        ->and($f[0]->moneyAtRiskPerDay)->toBe(150.0)->and($f[0]->evidence['learning'])->toBeFalse();
});

it('clears as soon as a purchase lands today (lag and today count, R-01)', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    W::spendDays($ad, 8, 150);
    W::spend($ad, W::day(0), 0, ['purchases' => 1]);

    expect(app(SpendNoResult::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});

it('warns at two times the target', function () {
    $acc = W::account();
    W::spendDays(W::ad($acc), 8, 75);

    $f = app(SpendNoResult::class)->evaluate(RuleContext::for($acc));

    expect($f[0]->severity)->toBe('medium')->and($f[0]->params['k'])->toBe(2.0);
});

it('speaks during learning with its own sentence', function () {
    $acc = W::account();
    W::spendDays(W::ad($acc), 4, 300);

    $f = app(SpendNoResult::class)->evaluate(RuleContext::for($acc));

    expect($f[0]->sentenceKey)->toBe('spend_no_result_learning')->and($f[0]->severity)->toBe('high')->and($f[0]->evidence['learning'])->toBeTrue();
});

it('fires the hard cap with no reference, even in learning', function () {
    app(RuleSettings::class)->saveInputs(W::authority(), null, ['target_cpp' => null]);
    $acc = W::account();
    W::spendDays(W::ad($acc), 2, 800);

    $f = app(SpendNoResult::class)->evaluate(RuleContext::for($acc));

    expect($f[0]->sentenceKey)->toBe('spend_no_result_cap')->and($f[0]->severity)->toBe('high')
        ->and($f[0]->params)->toMatchArray(['spend' => 1600.0, 'k' => null, 'cap' => 1500.0]);
});

it('counts a referral today as a messages result', function () {
    $acc = W::account();
    $ad = W::ad($acc, 'MESSAGES');
    W::spendDays($ad, 2, 800);

    expect(app(SpendNoResult::class)->evaluate(RuleContext::for($acc))[0]->params['result'])->toBe('chat');

    W::referral($ad, W::day(0).' 09:00');
    expect(app(SpendNoResult::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});

it('does not judge an ad Meta barely delivers', function () {
    $acc = W::account();
    $ad = W::ad($acc);
    $sibling = W::ad($acc, 'OUTCOME_SALES', [], $ad->adSet);
    W::spendDays($ad, 8, 150);
    W::spendDays($sibling, 8, 3000, -1, ['purchases' => 5, 'purchase_value' => 9000]);

    expect(app(SpendNoResult::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});
