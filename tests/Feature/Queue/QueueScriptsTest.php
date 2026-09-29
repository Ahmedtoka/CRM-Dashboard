<?php

use App\Bot\Flows\FlowScripts;
use App\Models\BotKnowledgeEntry;
use App\Queue\QueueScripts;

it('seeds queue scripts and fills placeholders', function () {
    expect(FlowScripts::all())->toHaveKeys(['queue_enqueued', 'queue_night', 'queue_left_5', 'queue_left_3', 'queue_left_1', 'queue_apology', 'queue_called', 'queue_auto_closed', 'queue_returning', 'queue_reassigned', 'queue_review_ask', 'queue_review_thanks', 'queue_case_opened', 'queue_case_resolved', 'queue_silence_warning']);
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
