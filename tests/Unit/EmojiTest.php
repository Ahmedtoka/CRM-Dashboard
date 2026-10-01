<?php

use App\Support\Emoji;

it('finds and strips pictographs, variation selectors, joiners, skin tones and keycaps', function (string $in, string $out) {
    expect(Emoji::strip($in))->toBe($out);
})->with([
    ['سعدنا بخدمتك يا فندم 🌸', 'سعدنا بخدمتك يا فندم'],
    ['اختاري من القائمة 👇🏻', 'اختاري من القائمة'],
    ['❤️ شكراً', 'شكراً'],
    ['📍 الخريطة', 'الخريطة'],
    ["تمام ✅\nهنبعتلك 🚚 الشحنة", "تمام\nهنبعتلك الشحنة"],
    ['👨‍👩‍👧 عيلة', 'عيلة'],
    ['1️⃣ الأول', '1 الأول'],
    ['★★★ ممتاز ✓', 'ممتاز'],
    ['رقم #1047 — 250 ج.م «مقاس L» ← هنا', 'رقم #1047 — 250 ج.م «مقاس L» ← هنا'],   // digits, #, «», ←, — stay
    ['', ''],
]);

it('says whether a text has any', function () {
    expect(Emoji::contains('أهلاً 🌸'))->toBeTrue()
        ->and(Emoji::contains('أهلاً #12 ← ٣'))->toBeFalse();
});

it('strips every string leaf of nested arrays and leaves other values alone', function () {
    expect(Emoji::stripDeep(['text' => 'هاي 👋', 'n' => 3, 'opts' => [['title' => '🛍️ تسوقي']], 'ok' => true]))
        ->toBe(['text' => 'هاي', 'n' => 3, 'opts' => [['title' => 'تسوقي']], 'ok' => true]);
});
