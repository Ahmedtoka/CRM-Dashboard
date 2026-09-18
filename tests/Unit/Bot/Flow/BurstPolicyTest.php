<?php

use App\Bot\Flow\BurstPolicy;

it('uses the base wait for a finished question', function () {
    expect((new BurstPolicy)->waitSeconds(['بكام الطقم ده؟'], false, 8, 25))->toBe(8);
});

it('waits longer when the customer looks unfinished', function (array $texts, bool $attachment) {
    expect((new BurstPolicy)->waitSeconds($texts, $attachment, 8, 25))->toBe(25);
})->with([
    'hold on word' => [['استنى ثانية'], false],
    'trailing connector' => [['عايزة الفستان و'], false],
    'text then photo' => [['ده'], true],
    'ellipsis' => [['بصي...'], false],
    // Overnight refinement change 5: still-typing signals.
    'starts with و + more text' => [['وكمان عايزة الاسود'], false],
    'starts with وكمان + more text' => [['وكمان هبعت الصورة كمان شوية'], false],
    'starts with بردو + more text' => [['بردو عايزة اسأل حاجة'], false],
    'starts with برضو + more text' => [['برضو نفس الكلام'], false],
    // Longer than 2 words and no "?": only the HOLD_WORDS addition can catch these.
    'contains هبعتلك' => [['هبعتلك صورة المنتج دلوقتي'], false],
    // Fix round 1, issue 3: plain "لسه" was removed from HOLD_WORDS (false positive on
    // complete complaints); only "لسه هبعت"/"لسه بكتب" are hold signals now.
    'contains لسه هبعت' => [['لسه هبعت الصورة دلوقتي'], false],
    'contains لسه بكتب' => [['لسه بكتب الرد على حضرتك'], false],
]);

it('does not treat a plain complaint that happens to contain لسه as unfinished (fix round 1, issue 3)', function () {
    // "لسه موصلش الاوردر" ("it still hasn't arrived") is a complete complaint, not a sign
    // she is still typing -- only removed once bare "لسه" left HOLD_WORDS.
    expect((new BurstPolicy)->waitSeconds(['لسه موصلش الاوردر'], false, 8, 25))->toBe(8);
});

it('does not treat the connector rule as unfinished when the text ends in a question mark (fix round 1, issue 2)', function (string $text) {
    expect((new BurstPolicy)->waitSeconds([$text], false, 8, 25))->toBe(8);
})->with([
    'و + question, latin ?' => ['و السعر كام?'],
    'و + question, arabic ؟' => ['و السعر كام؟'],
]);

it('still waits longer for the connector rule when there is no trailing question mark (fix round 1, issue 2)', function () {
    expect((new BurstPolicy)->waitSeconds(['وكمان عايزة الاسود'], false, 8, 25))->toBe(25);
});

it('does not treat a bare connector word alone as unfinished (no more text after it)', function (string $text) {
    expect((new BurstPolicy)->waitSeconds([$text], false, 8, 25))->toBe(25);
})->with([
    // Speed 2026-09-16: the short-reply rule is gone, so a lone connector is caught explicitly.
    'bare و' => ['و'],
    'bare وكمان' => ['وكمان'],
]);

it('still uses the base wait for an ordinary word that happens to start with و', function () {
    // "وحش" ("bad") starts with the "و" letter but is not the connector word "و" followed
    // by more text -- the base wait rule sees more than 2 words with a question mark, so
    // this exercises the negative case for the new starts-with-connector check specifically.
    expect((new BurstPolicy)->waitSeconds(['وحش قوي ده مش اللي طلبته؟'], false, 8, 25))->toBe(8);
});

it('treats a bare order detail or a confirmation word as a finished burst', function (string $text) {
    expect((new BurstPolicy)->waitSeconds([$text], false, 8, 25))->toBe(8);
})->with([
    'order number' => ['12345'],
    'hashed order number' => ['#12345'],
    'arabic digits' => ['١٢٣٤٥'],
    'phone' => ['01012345678'],
    'email' => ['mona@example.com'],
    'تمام' => ['تمام'],
    'ايوه' => ['ايوه'],
    'ok' => ['ok'],
]);

it('returns zero when both limits are zero', function () {
    expect((new BurstPolicy)->waitSeconds(['استنى'], false, 0, 0))->toBe(0);
});

it('treats short greetings and thanks as finished (speed fix 2026-09-16)', function (string $text) {
    expect((new BurstPolicy)->waitSeconds([$text], false, 4, 12))->toBe(4);
})->with(['مساء الخير', 'مساء الفل', 'صباح الورد', 'شكرا يا قمر', 'السلام عليكم', 'صباح الخير 🌸', 'Hi', 'شكرا ليكي', 'متشكرة!', 'تسلمي ايدك', 'thank you']);

it('answers short messages without a still-typing signal after the base wait (speed 2026-09-16)', function (string $text) {
    expect((new BurstPolicy)->waitSeconds([$text], false, 4, 12))->toBe(4);
})->with(['بكام', 'عنوان الفرع', 'عايزة']);

it('does not treat a longer message opening with a greeting as finished by the greeting rule', function () {
    expect((new BurstPolicy)->waitSeconds(['مساء الخير عايزة اسأل على'], false, 4, 12))->toBe(4)
        ->and((new BurstPolicy)->waitSeconds(['صباح'], false, 4, 12))->toBe(4);
});
