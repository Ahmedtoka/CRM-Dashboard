<?php

use App\Ads\Reports\AdHealth;

it('shows at most two badges in priority order', function () {
    expect(AdHealth::badges(['need_stop' => true, 'tier' => 'loser', 'fatigue' => ['flag' => true], 'parent_paused' => true]))
        ->toBe(['out_of_stock', 'losing']);
});

it('marks a running ad below the scoring gate as too early, never a paused one', function () {
    expect(AdHealth::badges(['scored' => false, 'effective_status' => 'ACTIVE']))->toBe(['too_early'])
        ->and(AdHealth::badges(['scored' => false, 'effective_status' => 'PAUSED']))->toBe([]);
});

it('shows winning and parent paused together', function () {
    expect(AdHealth::badges(['tier' => 'winner', 'parent_paused' => true, 'scored' => true]))->toBe(['parent_paused', 'winning']);
});

it('shows nothing for a healthy neutral ad', function () {
    expect(AdHealth::badges(['tier' => 'neutral', 'scored' => true, 'fatigue' => ['flag' => false]]))->toBe([]);
});
