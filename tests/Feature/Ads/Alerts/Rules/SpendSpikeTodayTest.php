<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Rules\SpendSpikeToday;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

function spikeWorld(float $today, string $name = 'LV-Main 2')
{
    $acc = W::account(['name' => $name]);
    $ad = W::ad($acc);
    W::spendDays($ad, 14, 1000);
    W::spend($ad, W::day(0), $today);

    return $acc;
}

it('flags an account spending more than twice its usual pace by this hour', function () {
    $acc = spikeWorld(2000);

    $f = app(SpendSpikeToday::class)->evaluate(RuleContext::for($acc));

    expect($f)->toHaveCount(1)
        ->and($f[0]->entityLevel)->toBe('account')->and($f[0]->entityId)->toBe($acc->id)->and($f[0]->adId)->toBeNull()
        ->and($f[0]->severity)->toBe('critical')->and($f[0]->action)->toBe('look')->and($f[0]->sentenceKey)->toBe('spend_spike_today')
        ->and($f[0]->params)->toBe(['account' => 'LV-Main 2', 'spend' => 2000.0, 'ratio' => 4.8, 'usual' => 417.0])
        ->and($f[0]->moneyAtRiskPerDay)->toBe(3800.0);
});

it('stays quiet below twice the pace or below the owner minimum', function (float $today) {
    expect(app(SpendSpikeToday::class)->evaluate(RuleContext::for(spikeWorld($today))))->toBe([]);
})->with([800.0, 900.0]);

it('does not shout at dawn: the expected spend never drops under a quarter day', function () {
    W::freeze('2026-10-06 02:00:00');
    $acc = spikeWorld(400);

    expect(app(SpendSpikeToday::class)->evaluate(RuleContext::for($acc)))->toBe([]);
});

it('closes for the rest of the Cairo day', function () {
    $now = W::freeze();

    expect(app(SpendSpikeToday::class)->cooldownUntil($now)->toDateTimeString())->toBe('2026-10-06 23:59:59');
});
