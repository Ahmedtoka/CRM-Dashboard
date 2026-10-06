<?php

use App\Ads\Alerts\RuleSettings;
use App\Models\AdsAuditLog;
use Tests\Support\AlertWorld as W;

it('starts in shadow mode with the documented defaults', function () {
    $s = app(RuleSettings::class);

    expect($s->notifyEnabled())->toBeFalse()
        ->and($s->lowStockUnits())->toBe(10)
        ->and($s->spikeMinAmount())->toBe(1000.0)
        ->and($s->globalInputs())->toBe(['margin_pct' => null, 'shipping_subsidy' => null, 'return_cost' => null, 'target_cpp' => null, 'target_cpo' => null]);
});

it('resolves account values over global ones and says where each came from', function () {
    $s = app(RuleSettings::class);
    $admin = W::authority();
    $s->saveInputs($admin, null, ['margin_pct' => 55, 'shipping_subsidy' => 60]);
    $s->saveInputs($admin, 7, ['margin_pct' => 50]);

    expect($s->inputsFor(7))->toBe([
        'values' => ['margin_pct' => 50.0, 'shipping_subsidy' => 60.0, 'return_cost' => null, 'target_cpp' => null, 'target_cpo' => null],
        'scope' => ['margin_pct' => 'account', 'shipping_subsidy' => 'global', 'return_cost' => 'none', 'target_cpp' => 'none', 'target_cpo' => 'none'],
    ]);
});

it('clears an account value back to inherit and audits only real changes', function () {
    $s = app(RuleSettings::class);
    $admin = W::authority();
    $s->saveInputs($admin, 7, ['margin_pct' => 50]);
    $s->saveInputs($admin, 7, ['margin_pct' => null]);
    $s->saveInputs($admin, 7, ['margin_pct' => '']);

    $rows = AdsAuditLog::query()->where('action', 'settings.breakeven_changed')->orderBy('id')->get();
    expect($s->accountInputs(7)['margin_pct'])->toBeNull()
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]->after['margin_pct'])->toEqual(50.0)
        ->and($rows[1]->before['margin_pct'])->toEqual(50.0);
});

it('switches notifications and the general numbers with an audit row each', function () {
    $s = app(RuleSettings::class);
    $admin = W::authority();
    $s->setNotify($admin, true);
    $s->saveGeneral($admin, ['low_stock_units' => 15, 'spike_min_amount' => 2500]);

    expect($s->notifyEnabled())->toBeTrue()->and($s->lowStockUnits())->toBe(15)->and($s->spikeMinAmount())->toBe(2500.0)
        ->and(AdsAuditLog::query()->where('action', 'settings.alerts_notify')->count())->toBe(1)
        ->and(AdsAuditLog::query()->where('action', 'settings.alerts_changed')->count())->toBe(1);
});
