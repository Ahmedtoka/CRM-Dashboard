<?php

/** The banner and the bell read these keys by name; a key in the wrong block shows as raw text. */
function hi18nBlock(string $file, string $opener, string $closer, int $from = 0): string
{
    $s = file_get_contents(base_path($file));
    $a = strpos($s, $opener, $from);
    expect($a)->not->toBeFalse("{$opener} not found in {$file}");
    $b = strpos($s, $closer, $a + strlen($opener));

    return substr($s, $a, $b - $a);
}

it('has the banner keys under the top-level ads block in both locales', function (string $locale) {
    $file = "resources/js/i18n/{$locale}.ts";
    $ads = hi18nBlock($file, "\n    ads: {", "\n    },\n");
    $health = hi18nBlock($file, 'health: {', "\n        },", strpos(file_get_contents(base_path($file)), $ads));

    expect($ads)->toContain('health: {');
    foreach (['title:', 'and_more:', 'reasons: {'] as $key) {
        expect($health)->toContain($key);
    }
    foreach (['reconnect', 'stale', 'read_only', 'incomplete', 'gap', 'history_start', 'under_review'] as $reason) {
        expect($health)->toContain("{$reason}:");
    }

    // and nothing of it is left in the reports block, where t('ads.health.*') cannot see it
    $reports = hi18nBlock($file, "\n        ads: {", "\n        },\n");
    expect($reports)->not->toContain('health: {');
})->with(['en', 'ar']);

it('has a notification label for every health reason in both locales', function (string $locale) {
    $block = hi18nBlock("resources/js/i18n/{$locale}.ts", 'ads_health_reasons: {', '},');

    foreach (['stale', 'reconnect', 'read_only', 'stuck_runs', 'control_gap', 'usage', 'scheduler', 'queue', 'assignments_overlap'] as $reason) {
        expect($block)->toContain("{$reason}:");
    }
})->with(['en', 'ar']);
