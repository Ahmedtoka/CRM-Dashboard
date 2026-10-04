<?php

use App\Ads\Naming;

it('accepts campaign names that follow the convention', function (string $name, bool $ok) {
    expect(Naming::checkCampaign($name))->toBe($ok);
})->with([
    ['LV | Black Abaya | Sales | Ahmed | 261004', true],
    ['LV | Hijab | Messages | Mostafa | 260101', true],
    ['Sales 1', false],
    ['LV | Black Abaya | Sales | Ahmed | 2610', false],
    ['LV | Black Abaya | Sales | Ahmed | 261004 copy', false],
    ['lv | Black Abaya | Sales | Ahmed | 261004', false],
    ['', false],
]);

it('accepts ad set names of three parts', function (string $name, bool $ok) {
    expect(Naming::checkAdSet($name))->toBe($ok);
})->with([
    ['Broad | EG | Advantage+', true],
    ['Broad', false],
    ['Broad | EG', false],
    ['', false],
]);

it('builds the ad name the system gives its ads', function () {
    expect(Naming::adName(42, 'Reel', 2))->toBe('M42 | Reel | C2')
        ->and(Naming::adName(7, 'image', 1))->toBe('M7 | Image | C1')
        ->and(Naming::adName(7, 'whatever', 3))->toBe('M7 | Post | C3');
});
