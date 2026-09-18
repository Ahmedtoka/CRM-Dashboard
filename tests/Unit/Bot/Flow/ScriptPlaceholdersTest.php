<?php

use App\Bot\Flow\ScriptPlaceholders;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('renders {time_greeting} for the given Africa/Cairo local time', function (string $time, string $expected) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse($time, 'Africa/Cairo'));

    expect((new ScriptPlaceholders)->render('{time_greeting} يا فندم'))->toBe($expected.' يا فندم');
})->with([
    'just before the morning window' => ['2026-09-15 04:59:00', 'مساء الخير'],
    'morning window starts' => ['2026-09-15 05:00:00', 'صباح الخير'],
    'morning window ends' => ['2026-09-15 11:59:00', 'صباح الخير'],
    'evening window starts' => ['2026-09-15 12:00:00', 'مساء الخير'],
]);

it('leaves a body with no placeholder untouched', function () {
    expect((new ScriptPlaceholders)->render('نص عادي بدون بديل'))->toBe('نص عادي بدون بديل');
});

it('converts a UTC test time to Africa/Cairo before deciding the greeting', function () {
    // 2026-09-15 03:00 UTC == 05:00 Cairo (UTC+2, no DST) -> morning.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 03:00:00', 'UTC'));

    expect((new ScriptPlaceholders)->render('{time_greeting}'))->toBe('صباح الخير');
});
