<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Rules\PriceMismatch;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

function pmEval(string $body, float $price, bool $twoProducts = false): array
{
    $acc = W::account();
    $ad = W::ad($acc, 'OUTCOME_SALES', ['body' => $body]);
    W::spendDays($ad, 3, 200);
    W::link($ad, W::product([5, 5], $price));
    if ($twoProducts) {
        W::link($ad, W::product([5], 400, ['title' => 'Hijab']));
    }

    return app(PriceMismatch::class)->evaluate(RuleContext::for($acc));
}

it('flags a caption price that is not the site price', function () {
    $f = pmEval('عباية نور بـ 950 جنيه بس', 1100);

    expect($f)->toHaveCount(1)->and($f[0]->severity)->toBe('high')->and($f[0]->action)->toBe('edit_ad')
        ->and($f[0]->params)->toMatchArray(['caption_price' => 950, 'site_price' => 1100, 'product' => 'Abaya Noor'])
        ->and($f[0]->moneyAtRiskPerDay)->toBe(200.0);
});

it('accepts the site price written in Arabic digits', function () {
    expect(pmEval('السعر ١١٠٠ جنيه', 1100))->toBe([]);
});

it('stays quiet without a price in the caption or with several products', function () {
    expect(pmEval('عباية نور الجديدة', 1100))->toBe([])
        ->and(pmEval('بـ 950 جنيه', 1100, true))->toBe([]);
});
