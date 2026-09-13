<?php

use App\Bot\PriceGuard;

it('rejects invented prices', function () {
    $lines = ['فستان ستان | SKU DR-101 | 1250 جنيه | متاح 7'];
    expect(app(PriceGuard::class)->isSafe('سعره 1250 جنيه', $lines))->toBeTrue()
        ->and(app(PriceGuard::class)->isSafe('سعره 999 جنيه بس النهارده', $lines))->toBeFalse()
        ->and(app(PriceGuard::class)->isSafe('المقاس 3 متاح', $lines))->toBeTrue();
});

it('normalizes digits and separators before comparing prices', function () {
    $lines = ['فستان ستان | SKU DR-101 | 1250 جنيه | متاح 7'];

    // Arabic-Indic digits: an invented price must still be caught.
    expect(app(PriceGuard::class)->isSafe('سعره ٩٩٩ جنيه', $lines))->toBeFalse()
        // A thousands separator shouldn't split a real price into fake ones.
        ->and(app(PriceGuard::class)->isSafe('سعره 1,250 جنيه', $lines))->toBeTrue()
        // Arabic-Indic digits for the real price.
        ->and(app(PriceGuard::class)->isSafe('سعره ١٢٥٠ جنيه', $lines))->toBeTrue()
        // A trailing ".00" is still the same number as the catalog's "1250".
        ->and(app(PriceGuard::class)->isSafe('سعره 1250.00 جنيه', $lines))->toBeTrue();
});
