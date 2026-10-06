<?php

use App\Ads\Launch\CaptionPriceParser;

it('reads EGP prices next to a currency word', function (string $text, array $prices) {
    expect(CaptionPriceParser::prices($text))->toBe($prices);
})->with([
    'arabic word after' => ['عباية حرير ناعمة بـ 450 جنيه بس', [450]],
    'arabic digits and ج' => ['السعر ٤٥٠ ج', [450]],
    'arabic thousands' => ['السعر ١٬٢٥٠ جنيه', [1250]],
    'latin thousands, prefix' => ['EGP 1,250 only', [1250]],
    'LE suffix' => ['Price 450 LE', [450]],
    'decimals dropped' => ['450.50 EGP', [450]],
    'two prices' => ['2 قطعة بـ 900 جنيه بدل 1200 جنيه', [900, 1200]],
    'ج.م' => ['بـ 650 ج.م', [650]],
    'no currency' => ['خصم 20% على 3 قطع', []],
    'ج inside a word is not money' => ['موديل جديد 450', []],
    'sizes' => ['مقاسات 38 - 42', []],
    'persian digits' => ['۴۵۰ جنيه', [450]],
]);
