<?php

use App\Bot\ArabicNormalizer;

it('normalizes egyptian arabic variants', function (string $in, string $out) {
    expect(app(ArabicNormalizer::class)->normalize($in))->toBe($out);
})->with([
    ['بِكَــــام', 'بكام'], ['إزاي', 'ازاي'], ['متاحةةة', 'متاحه'], ['مستشفى', 'مستشفي'], ['٣ قطع', '3 قطع'], ['PRICE', 'price'],
]);
