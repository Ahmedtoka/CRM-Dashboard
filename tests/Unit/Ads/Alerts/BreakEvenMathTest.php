<?php

use App\Ads\Alerts\BreakEven;

it('matches the worked example of the recommendations review', function () {
    // AOV 1,200, margin 55 %, shipping 60, refusal 20 % costing 70, VAT 14 %: (0.8 x 600 - 14) / 1.14 = 408.77; F = 2.94
    $cpa = BreakEven::maxCpa(1200, 55, 60, 70, 0.2, 0.14);

    expect($cpa)->toBe(408.77)->and(round(1200 / $cpa, 2))->toBe(2.94);
});

it('returns null when a placed order loses money before any ad spend', function () {
    expect(BreakEven::maxCpa(1200, 5, 60, 70, 0.2, 0.14))->toBeNull();
});
