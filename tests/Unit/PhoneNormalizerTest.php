<?php

use App\Shopify\Customers\PhoneNormalizer;

it('normalizes egyptian mobiles to e164', function (?string $in, ?string $out) {
    expect(PhoneNormalizer::toE164($in))->toBe($out);
})->with([
    ['01001234567', '+201001234567'],
    ['0100 123 4567', '+201001234567'],
    ['٠١٠٠١٢٣٤٥٦٧', '+201001234567'],
    ['201001234567', '+201001234567'],
    ['00201001234567', '+201001234567'],
    ['+201551234567', '+201551234567'],
    ['+971501234567', '+971501234567'],
    ['0223456789', '0223456789'],
    ['', null],
    [null, null],
]);

it('detects egyptian mobiles', function () {
    expect(PhoneNormalizer::isEgyptianMobile('+201201234567'))->toBeTrue()
        ->and(PhoneNormalizer::isEgyptianMobile('+201301234567'))->toBeFalse()
        ->and(PhoneNormalizer::isEgyptianMobile('0223456789'))->toBeFalse();
});
