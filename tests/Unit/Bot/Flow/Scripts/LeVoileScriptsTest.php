<?php

use App\Bot\Flow\Scripts\LeVoileScripts;

/**
 * Fix round 1, issue 5: the scarves word list must match whole words after
 * normalization, not substrings ("كاب" inside "كابوس", "طرح" inside "مطروح").
 * mentionsScarves() is the single decision point TurnRunner::bodies() uses for
 * both script.availability and script.not_available (the "out of stock" reply),
 * so a direct unit test here covers both scripts' link-swap decision.
 */
it('does not match a scarf/cap word as a substring of an unrelated word', function (string $text) {
    expect(LeVoileScripts::mentionsScarves($text))->toBeFalse();
})->with([
    'كابوس contains كاب' => ['الشحن كان كابوس'],
    'مطروح contains طرح' => ['محافظة مطروح'],
    'كابينة contains كاب' => ['جربتها في الكابينة'],
]);

it('matches a scarf/cap word as a whole word', function (string $text) {
    expect(LeVoileScripts::mentionsScarves($text))->toBeTrue();
})->with([
    'كاب' => ['عايزة كاب'],
    'طرح' => ['عندكم طرح ملون؟'],
    'طرحه' => ['الطرحه دي لسه موجودة؟'],
    'بونيهات' => ['فيه بونيهات كتير؟'],
    'latin scarf' => ['do you have a scarf?'],
    'latin cap' => ['need a cap'],
]);

/*
 * Fix round 2: round 1's bare-"ال"-only stripping regressed a scarf word with an
 * attached preposition ("بالطرح", "للكاب") back to a false negative. The controller's
 * ruling: strip at most one leading attached letter from ب/ل/و/ك/ف, then optionally
 * "ال" (also accepting the doubled-lam "لل" contraction), accepting the token if it
 * equals a scarf word before OR after stripping -- while still rejecting substrings.
 */
it('matches a scarf/cap word with one attached preposition letter (fix round 2)', function (string $text) {
    expect(LeVoileScripts::mentionsScarves($text))->toBeTrue();
})->with([
    'بالطرحة (ب + ال + طرحة)' => ['الفستان بيجي بالطرحة؟'],
    'للكاب (لل contraction + كاب)' => ['عايزة اكسسوار للكاب'],
    'والكابات (و + ال + كابات)' => ['والكابات كمان متاحة؟'],
    'بالطرح (ب + ال + طرح)' => ['الفستان بيجي بالطرح؟'],
]);

it('still rejects a substring even with an attached preposition letter present (fix round 2)', function (string $text) {
    expect(LeVoileScripts::mentionsScarves($text))->toBeFalse();
})->with([
    'كابوس (ك is a valid attached letter, but the rest is not a scarf word)' => ['كابوس'],
    'مطروح (does not start with an attached letter or ال/لل at all)' => ['مطروح'],
    'فستان (ف is a valid attached letter, but the rest is not a scarf word)' => ['فستان'],
]);
