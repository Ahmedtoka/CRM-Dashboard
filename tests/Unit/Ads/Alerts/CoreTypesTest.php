<?php

use App\Ads\Alerts\Family;
use App\Ads\Alerts\Finding;
use App\Ads\Alerts\Severity;
use App\Ads\Alerts\Stats;

it('maps campaign objectives to families', function (?string $objective, int $msg, string $family) {
    expect(Family::of($objective, $msg))->toBe($family);
})->with([
    ['OUTCOME_SALES', 0, Family::SALES],
    ['CONVERSIONS', 0, Family::SALES],
    ['MESSAGES', 0, Family::MESSAGES],
    ['OUTCOME_ENGAGEMENT', 12, Family::MESSAGES],
    ['OUTCOME_ENGAGEMENT', 0, Family::OTHER],
    ['outcome_traffic', 0, Family::TRAFFIC],
    [null, 5, Family::OTHER],
]);

it('ranks severities', function () {
    expect(Severity::rank(Severity::CRITICAL))->toBeGreaterThan(Severity::rank(Severity::HIGH))
        ->and(Severity::rank(Severity::MEDIUM))->toBeGreaterThan(Severity::rank(Severity::INFO))
        ->and(Severity::max(Severity::MEDIUM, Severity::HIGH))->toBe(Severity::HIGH)
        ->and(Severity::rank('nonsense'))->toBe(0);
});

it('takes medians of odd, even and empty lists', function () {
    expect(Stats::median([3, 1, 2]))->toBe(2.0)
        ->and(Stats::median([4, 1, 3, 2]))->toBe(2.5)
        ->and(Stats::median([]))->toBeNull();
});

it('fingerprints a finding by kind, rule and entity and copies it with changes', function () {
    $f = new Finding('all.spend_no_result', Severity::HIGH, 'stop', 'ad', 42, 7, 42, null, Family::SALES, 150.0, 'spend_no_result', ['k' => 3], []);
    $g = $f->with(['action' => 'add_replacement']);

    expect($f->fingerprint())->toBe('alert:all.spend_no_result:ad:42')
        ->and($g->action)->toBe('add_replacement')->and($g->ruleId)->toBe('all.spend_no_result')->and($f->action)->toBe('stop');
});
