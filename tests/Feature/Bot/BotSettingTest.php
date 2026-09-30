<?php

use App\Models\BotSetting;

it('keeps one settings row under id 1 however often it is read, even after the auto-increment moved on', function () {
    // MySQL keeps counting after a delete (sqlite reuses ids); `id` is guarded, so a firstOrCreate
    // on it would have created a fresh row on every read.
    BotSetting::current();
    BotSetting::query()->delete();

    $first = BotSetting::current();
    $again = BotSetting::current();

    expect($first->id)->toBe(1)
        ->and($again->id)->toBe(1)
        ->and(BotSetting::count())->toBe(1);

    BotSetting::current()->update(['ai_classifier_model' => 'claude-owner-classifier']);

    expect(BotSetting::current()->ai_classifier_model)->toBe('claude-owner-classifier')
        ->and(BotSetting::count())->toBe(1);
});
