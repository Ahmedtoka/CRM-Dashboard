<?php

use App\Bot\Flows\FlowScripts;
use App\Models\BotKnowledgeEntry;
use App\Queue\QueueScripts;

/** What 200032 wrote (the defaults before 200060 worded the counts). */
const WORDED_TICKET_ENQUEUED = 'تمام ✅ هيتم تحويلك لموظفة خدمة العملاء. رقم تذكرتك #{ticket}، وقدامك {ahead} وحوالي {eta_minutes} دقيقة 🌸';
const WORDED_TICKET_RETURNING = 'أهلاً بيكي تاني 🌸 بنرجّعك لنفس الموظفة بأولوية، رقم تذكرتك #{ticket} وقدامك حوالي {eta_minutes} دقيقة.';

it('seeds queue scripts and fills placeholders', function () {
    expect(FlowScripts::all())->toHaveKeys(['queue_enqueued', 'queue_night', 'queue_left_5', 'queue_left_3', 'queue_left_1', 'queue_apology', 'queue_called', 'queue_auto_closed', 'queue_returning', 'queue_reassigned', 'queue_review_ask', 'queue_review_thanks', 'queue_case_opened', 'queue_case_resolved', 'queue_silence_warning', 'queue_enqueued_no_eta', 'queue_position_update', 'queue_eta_sentence', 'queue_agent_delay_apology', 'queue_closed_thanks']);
    expect(BotKnowledgeEntry::where('key', 'script.queue_enqueued')->exists())->toBeTrue();
    $t = app(QueueScripts::class)->text('queue_enqueued', ['ticket' => 52, 'eta_minutes' => 4, 'position' => 3]);
    expect($t)->toContain('52')->toContain('4');
    BotKnowledgeEntry::where('key', 'script.queue_left_5')->update(['is_active' => false]);
    expect(app(QueueScripts::class)->text('queue_left_5'))->toBeNull();
});

it('fills case_id on the active seeded row, not an empty gap', function () {
    expect(BotKnowledgeEntry::where('key', 'script.queue_case_opened')->value('is_active'))->toBeTrue()
        ->and(BotKnowledgeEntry::where('key', 'script.queue_case_resolved')->value('is_active'))->toBeTrue();

    expect(app(QueueScripts::class)->text('queue_case_opened', ['case_id' => 4321]))->toContain('4321')
        ->and(app(QueueScripts::class)->text('queue_case_resolved', ['case_id' => 4321]))->toContain('4321');
});

it('rewords the stored ticket texts only where the owner has not edited them, and restores them on rollback', function () {
    $wording = require database_path('migrations/2026_09_29_200032_reword_queue_ticket_scripts.php');
    $old = 'تمام ✅ هيتم تحويلك لموظفة خدمة العملاء. رقمك في الدور {ticket} وقدامك حوالي {eta_minutes} دقيقة 🌸';
    BotKnowledgeEntry::where('key', 'script.queue_enqueued')->update(['body' => $old]);
    BotKnowledgeEntry::where('key', 'script.queue_returning')->update(['body' => 'نصي أنا {ticket}']);
    BotKnowledgeEntry::where('key', 'script.queue_night')->update(['body' => 'x '.FlowScripts::all()['queue_night']['body']]);

    $wording->up();

    expect(BotKnowledgeEntry::where('key', 'script.queue_enqueued')->value('body'))->toBe(WORDED_TICKET_ENQUEUED)
        ->and(BotKnowledgeEntry::where('key', 'script.queue_returning')->value('body'))->toBe('نصي أنا {ticket}')
        ->and(BotKnowledgeEntry::where('key', 'script.queue_night')->value('body'))->toStartWith('x ');

    $wording->down();

    expect(BotKnowledgeEntry::where('key', 'script.queue_enqueued')->value('body'))->toBe($old)
        ->and(BotKnowledgeEntry::where('key', 'script.queue_returning')->value('body'))->toBe('نصي أنا {ticket}');
});

it('matches the previous default byte for byte, so an edit a collation would ignore is kept', function () {
    $wording = require database_path('migrations/2026_09_29_200032_reword_queue_ticket_scripts.php');
    $oldEnqueued = 'تمام ✅ هيتم تحويلك لموظفة خدمة العملاء. رقمك في الدور {ticket} وقدامك حوالي {eta_minutes} دقيقة 🌸';
    $oldReturning = 'أهلاً بيكي تاني 🌸 بنرجّعك لنفس الموظفة بأولوية، رقمك {ticket} وقدامك حوالي {eta_minutes} دقيقة.';
    $newEnqueued = WORDED_TICKET_ENQUEUED;

    // Owner edits that utf8mb4_unicode_ci equates with the default: another emoji, a dropped shadda, a trailing space.
    $editedEmoji = str_replace('🌸', '🌹', $oldEnqueued);
    $editedShadda = str_replace('ّ', '', $oldReturning);
    BotKnowledgeEntry::where('key', 'script.queue_enqueued')->update(['body' => $editedEmoji]);
    BotKnowledgeEntry::where('key', 'script.queue_returning')->update(['body' => $editedShadda]);
    BotKnowledgeEntry::where('key', 'script.queue_enqueued_no_eta')->update(['body' => FlowScripts::all()['queue_enqueued_no_eta']['body'].' ']);

    $wording->up();

    expect(BotKnowledgeEntry::where('key', 'script.queue_enqueued')->value('body'))->toBe($editedEmoji)
        ->and(BotKnowledgeEntry::where('key', 'script.queue_returning')->value('body'))->toBe($editedShadda);

    // down() is just as exact: the new default plus a trailing space, or with another emoji, is not restored.
    BotKnowledgeEntry::where('key', 'script.queue_enqueued')->update(['body' => $newEnqueued.' ']);
    BotKnowledgeEntry::where('key', 'script.queue_returning')->update(['body' => str_replace('🌸', '🌹', WORDED_TICKET_RETURNING)]);

    $wording->down();

    expect(BotKnowledgeEntry::where('key', 'script.queue_enqueued')->value('body'))->toBe($newEnqueued.' ')
        ->and(BotKnowledgeEntry::where('key', 'script.queue_returning')->value('body'))->toBe(str_replace('🌸', '🌹', WORDED_TICKET_RETURNING))
        ->and(BotKnowledgeEntry::where('key', 'script.queue_enqueued_no_eta')->value('body'))->toEndWith(' ');
});

it('words the counts of the stored scripts only where they are still the previous default, byte for byte, and restores them on rollback', function () {
    $wording = require database_path('migrations/2026_09_29_200060_reword_queue_count_scripts.php');
    $oldSentence = 'وهنكون معاكي خلال حوالي {minutes} دقايق';
    $oldWarning = 'لسه معانا يا فندم؟ 🌸 المحادثة هتتقفل تلقائي بعد {minutes} دقيقة لو مفيش رد، وتقدري تكتبيلنا في أي وقت وهنرجّعك بأولوية.';
    $oldUpdate = 'لسه معاكي 💛 رقم تذكرتك #{ticket}، وقدامك {ahead} {eta_sentence}';
    $body = fn (string $key) => BotKnowledgeEntry::where('key', 'script.'.$key)->value('body');

    BotKnowledgeEntry::where('key', 'script.queue_enqueued')->update(['body' => WORDED_TICKET_ENQUEUED]);
    BotKnowledgeEntry::where('key', 'script.queue_returning')->update(['body' => WORDED_TICKET_RETURNING]);
    BotKnowledgeEntry::where('key', 'script.queue_eta_sentence')->update(['body' => $oldSentence]);
    BotKnowledgeEntry::where('key', 'script.queue_silence_warning')->update(['body' => $oldWarning]);
    // Owner edits a collation would equate with the default: a trailing space, another emoji.
    BotKnowledgeEntry::where('key', 'script.queue_position_update')->update(['body' => $oldUpdate.' ']);

    $wording->up();

    // The defaults of that day (2026-09-29), emoji and all; the 2026-10-01 strip migration takes them on to today's.
    expect($body('queue_enqueued'))->toBe('تمام ✅ هيتم تحويلك لموظفة خدمة العملاء. رقم تذكرتك #{ticket}، و{ahead}، وهنكون معاكي خلال حوالي {eta_minutes} 🌸')
        ->and($body('queue_returning'))->toBe('أهلاً بيكي تاني 🌸 بنرجّعك لنفس الموظفة بأولوية، رقم تذكرتك #{ticket} وهنكون معاكي خلال حوالي {eta_minutes}.')
        ->and($body('queue_eta_sentence'))->toBe('وهنكون معاكي خلال حوالي {minutes}')
        ->and($body('queue_silence_warning'))->toBe('لسه معانا يا فندم؟ 🌸 المحادثة هتتقفل تلقائي بعد {minutes} لو مفيش رد، وتقدري تكتبيلنا في أي وقت وهنرجّعك بأولوية.')
        ->and($body('queue_position_update'))->toBe($oldUpdate.' ');

    (require database_path('migrations/2026_10_01_100010_strip_emoji_from_stored_texts.php'))->up();
    foreach (['queue_enqueued', 'queue_returning', 'queue_silence_warning'] as $key) {
        expect($body($key))->toBe(FlowScripts::all()[$key]['body']);
    }
    BotKnowledgeEntry::where('key', 'script.queue_enqueued')->update(['body' => 'تمام ✅ هيتم تحويلك لموظفة خدمة العملاء. رقم تذكرتك #{ticket}، و{ahead}، وهنكون معاكي خلال حوالي {eta_minutes} 🌸']);
    BotKnowledgeEntry::where('key', 'script.queue_eta_sentence')->update(['body' => 'وهنكون معاكي خلال حوالي {minutes}']);
    BotKnowledgeEntry::where('key', 'script.queue_position_update')->update(['body' => $oldUpdate.' ']);
    BotKnowledgeEntry::where('key', 'script.queue_silence_warning')->update(['body' => 'لسه معانا يا فندم؟ 🌸 المحادثة هتتقفل تلقائي بعد {minutes} لو مفيش رد، وتقدري تكتبيلنا في أي وقت وهنرجّعك بأولوية.']);

    // The 2026-09-29 default with another emoji: a collation would call it equal, 200060 must not.
    $editedReturning = 'أهلاً بيكي تاني 🌹 بنرجّعك لنفس الموظفة بأولوية، رقم تذكرتك #{ticket} وهنكون معاكي خلال حوالي {eta_minutes}.';
    BotKnowledgeEntry::where('key', 'script.queue_returning')->update(['body' => $editedReturning]);

    $wording->down();

    expect($body('queue_enqueued'))->toBe(WORDED_TICKET_ENQUEUED)
        ->and($body('queue_eta_sentence'))->toBe($oldSentence)
        ->and($body('queue_silence_warning'))->toBe($oldWarning)
        ->and($body('queue_returning'))->toBe($editedReturning)
        ->and($body('queue_position_update'))->toBe($oldUpdate.' ');
});

it('reads naturally with the worded counts', function () {
    $texts = app(QueueScripts::class);

    expect($texts->text('queue_enqueued', ['ticket' => 12, 'ahead' => 'إنتي أول واحدة في الدور', 'eta_minutes' => 'دقيقتين']))
        ->toBe('تمام، هيتم تحويلك لموظفة خدمة العملاء. رقم تذكرتك #12، وإنتي أول واحدة في الدور، وهنكون معاكي خلال حوالي دقيقتين')
        ->and($texts->text('queue_returning', ['ticket' => 7, 'eta_minutes' => '5 دقايق']))
        ->toBe('أهلاً بيكي تاني، بنرجّعك لنفس الموظفة بأولوية، رقم تذكرتك #7 وهنكون معاكي خلال حوالي 5 دقايق.')
        ->and($texts->text('queue_eta_sentence', ['minutes' => '15 دقيقة']))->toBe('وهنكون معاكي خلال حوالي 15 دقيقة')
        ->and($texts->text('queue_silence_warning', ['minutes' => 'دقيقة']))->toContain('هتتقفل تلقائي بعد دقيقة لو مفيش رد');
});

it('seeds the closing message with the approved text, and never overwrites an edited one', function () {
    $row = fn () => BotKnowledgeEntry::where('key', 'script.queue_closed_thanks');
    expect($row()->value('body'))->toBe('سعدنا بخدمتك يا فندم، لو احتجتي أي حاجة تانية ابعتيلنا في أي وقت.')
        ->and($row()->value('is_active'))->toBeTrue()
        ->and($row()->value('title'))->toBe('الطابور: رسالة القفل');

    $row()->update(['body' => 'نصي أنا']);
    (require database_path('migrations/2026_10_01_200020_seed_queue_closed_thanks_script.php'))->up();

    expect($row()->count())->toBe(1)->and($row()->value('body'))->toBe('نصي أنا');
});
