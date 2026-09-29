<?php

use App\Bot\Flows\FlowScripts;
use App\Models\BotKnowledgeEntry;
use App\Queue\QueueScripts;

it('seeds queue scripts and fills placeholders', function () {
    expect(FlowScripts::all())->toHaveKeys(['queue_enqueued', 'queue_night', 'queue_left_5', 'queue_left_3', 'queue_left_1', 'queue_apology', 'queue_called', 'queue_auto_closed', 'queue_returning', 'queue_reassigned', 'queue_review_ask', 'queue_review_thanks', 'queue_case_opened', 'queue_case_resolved', 'queue_silence_warning', 'queue_enqueued_no_eta', 'queue_position_update', 'queue_eta_sentence']);
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

    expect(BotKnowledgeEntry::where('key', 'script.queue_enqueued')->value('body'))->toBe(FlowScripts::all()['queue_enqueued']['body'])
        ->and(BotKnowledgeEntry::where('key', 'script.queue_returning')->value('body'))->toBe('نصي أنا {ticket}')
        ->and(BotKnowledgeEntry::where('key', 'script.queue_night')->value('body'))->toStartWith('x ');

    $wording->down();

    expect(BotKnowledgeEntry::where('key', 'script.queue_enqueued')->value('body'))->toBe($old)
        ->and(BotKnowledgeEntry::where('key', 'script.queue_returning')->value('body'))->toBe('نصي أنا {ticket}');
});
