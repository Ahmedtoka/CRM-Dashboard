<?php

use App\Bot\Language\FakeTranslationEngine;

it('marks a missing translation loudly inside the test suite', function () {
    expect((new FakeTranslationEngine)->translate(['سؤال مش متترجم'], 'en')[0])->toStartWith('en#');
});

it('sends the Arabic source outside tests, so a live customer never reads «en#…»', function () {
    // Production with a missing or misconfigured Anthropic key falls back to this engine.
    // Arabic is awkward for an English customer; a debug marker is unusable.
    expect((new FakeTranslationEngine(markGaps: false))->translate(['سؤال مش متترجم'], 'en'))
        ->toBe(['سؤال مش متترجم']);
});
