<?php

use App\Ads\Alerts\RuleContext;
use App\Ads\Alerts\Rules\OutOfStock;
use App\Ads\Alerts\Rules\ReactivateRestocked;
use App\Models\AdsAlert;
use Tests\Support\AlertWorld as W;

beforeEach(fn () => W::freeze());

function rrWorld(int $stock, string $status = 'PAUSED')
{
    $acc = W::account();
    $ad = W::ad($acc, 'OUTCOME_SALES', ['status' => $status, 'effective_status' => $status]);
    W::spendDays($ad, 3, 200, -3);
    $p = W::product([$stock]);
    W::link($ad, $p);
    AdsAlert::factory()->create(['ad_id' => $ad->id, 'rule_id' => OutOfStock::ID, 'state' => 'acted', 'product_id' => $p->id, 'closed_at' => now()->subDays(2)]);

    return $acc;
}

it('suggests running again an ad stopped for stock once the product is back above low stock', function () {
    $f = app(ReactivateRestocked::class)->evaluate(RuleContext::for(rrWorld(15)));

    expect($f)->toHaveCount(1)->and($f[0]->kind)->toBe('recommendation')->and($f[0]->severity)->toBe('high')->and($f[0]->action)->toBe('run')
        ->and($f[0]->params)->toBe(['product' => 'Abaya Noor', 'units' => 15])->and($f[0]->moneyAtRiskPerDay)->toBe(200.0);
});

it('waits while stock is at or under the low-stock units, and ignores ads already running', function () {
    expect(app(ReactivateRestocked::class)->evaluate(RuleContext::for(rrWorld(8))))->toBe([])
        ->and(app(ReactivateRestocked::class)->evaluate(RuleContext::for(rrWorld(15, 'ACTIVE'))))->toBe([]);
});
