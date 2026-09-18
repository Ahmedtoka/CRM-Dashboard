<?php

use App\Bot\Flow\Scripts\LeVoileScripts;
use App\Bot\Knowledge\KnowledgeDefaults;
use App\Bot\Knowledge\RealKnowledgeApplier;
use App\Models\BotIntent;
use App\Models\BotKnowledgeEntry;

/** Puts the database back to what the first seed left: every core row the flagged placeholder sample. */
function backToPlaceholderSamples(): void
{
    foreach (KnowledgeDefaults::LEGACY_SAMPLES as $key => $body) {
        BotKnowledgeEntry::where('key', $key)->update(['body' => $body, 'is_template' => true]);
    }

    BotKnowledgeEntry::where('key', 'script.payment_info')->update(['body' => LeVoileScripts::PAYMENT_PLACEHOLDER, 'is_active' => false]);
    BotKnowledgeEntry::where('key', 'script.shipping_fee')->delete();
    BotIntent::where('key', 'shipping_cost')->delete();
}

it('replaces untouched placeholder samples with the real texts and clears the sample flag', function () {
    backToPlaceholderSamples();

    $changed = app(RealKnowledgeApplier::class)->apply();

    foreach (KnowledgeDefaults::bodies() as $key => $body) {
        $row = BotKnowledgeEntry::where('key', $key)->sole();
        expect($row->body)->toBe($body)->and($row->is_template)->toBeFalse();
    }

    expect($changed)->toContain('payment_methods', 'script.payment_info', 'script.shipping_fee', 'intent.shipping_cost')
        ->and(BotKnowledgeEntry::where('key', 'script.payment_info')->first()->only(['body', 'is_active']))->toBe(['body' => LeVoileScripts::PAYMENT_TEXT, 'is_active' => true])
        ->and(BotIntent::where('key', 'shipping_cost')->value('script_keys'))->toBe(['shipping_fee']);
});

it('never overwrites a row the owner edited', function () {
    backToPlaceholderSamples();
    // Edited on the settings page: the flag is cleared.
    BotKnowledgeEntry::where('key', 'payment_methods')->update(['body' => 'كاش بس', 'is_template' => false]);
    // Saved the old sample text on purpose: also the owner's choice.
    BotKnowledgeEntry::where('key', 'working_hours_text')->update(['is_template' => false]);
    // Still flagged but the text is not the sample: not ours to replace.
    BotKnowledgeEntry::where('key', 'fabric_care')->update(['body' => 'غسيل جاف فقط']);
    BotKnowledgeEntry::where('key', 'script.payment_info')->update(['body' => 'فودافون كاش بس', 'is_active' => true]);

    $changed = app(RealKnowledgeApplier::class)->apply();

    expect($changed)->not->toContain('payment_methods')->not->toContain('working_hours_text')->not->toContain('fabric_care')->not->toContain('script.payment_info')
        ->and(BotKnowledgeEntry::where('key', 'payment_methods')->value('body'))->toBe('كاش بس')
        ->and(BotKnowledgeEntry::where('key', 'working_hours_text')->value('body'))->toBe(KnowledgeDefaults::LEGACY_SAMPLES['working_hours_text'])
        ->and(BotKnowledgeEntry::where('key', 'fabric_care')->value('body'))->toBe('غسيل جاف فقط')
        ->and(BotKnowledgeEntry::where('key', 'script.payment_info')->value('body'))->toBe('فودافون كاش بس')
        // The untouched ones still move.
        ->and(BotKnowledgeEntry::where('key', 'store_intro')->value('body'))->toBe(KnowledgeDefaults::bodies()['store_intro']);
});

it('is idempotent', function () {
    backToPlaceholderSamples();
    app(RealKnowledgeApplier::class)->apply();

    expect(app(RealKnowledgeApplier::class)->apply())->toBe([])
        ->and(BotIntent::where('key', 'shipping_cost')->count())->toBe(1)
        ->and(BotKnowledgeEntry::where('key', 'script.shipping_fee')->count())->toBe(1);
});

it('writes no shipping fee and no opening hours into the knowledge', function () {
    $bodies = KnowledgeDefaults::bodies();

    expect($bodies['shipping_times'])->not->toContain('جنيه')->toContain('3-5')->toContain('5-7')->toContain('7 لـ 10')
        ->and($bodies['working_hours_text'])->not->toMatch('/\d{1,2}\s*(ص|م|am|pm)/u')->toContain('24 ساعة')
        ->and($bodies['payment_methods'])->toContain('Apple Pay')->toContain('فيزا')
        ->and($bodies['exchange_policy'])->toContain('14 يوم')->toContain('البوركيني')->toContain('بدون مصاريف شحن')
        ->and($bodies['return_policy'])->toContain('7 لـ 14 يوم عمل')->toContain('سكرين شوت');
});
