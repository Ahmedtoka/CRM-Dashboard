<?php

use App\Models\QaReview;
use App\Models\QueueEntry;
use App\Models\ScoreEvent;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
});

it('creates the ledger and QA tables linked to the entry', function () {
    $u = User::factory()->create();
    $e = QueueEntry::factory()->create(['status' => 'closed', 'close_reason' => 'inquiry', 'assigned_user_id' => $u->id]);
    $line = ScoreEvent::factory()->create(['user_id' => $u->id, 'queue_entry_id' => $e->id, 'conversation_id' => $e->conversation_id]);
    $qa = QaReview::factory()->create(['queue_entry_id' => $e->id, 'conversation_id' => $e->conversation_id, 'user_id' => $u->id]);

    expect($e->scoreEvents)->toHaveCount(1)
        ->and($e->qaReview->id)->toBe($qa->id)
        ->and(DB::table('score_events')->value('business_date'))->toBe('2026-10-05')
        ->and($line->reason_vars)->toBeArray()
        ->and($qa->checklist)->toBeArray();

    expect(fn () => ScoreEvent::factory()->create(['user_id' => $u->id, 'unique_key' => $line->unique_key]))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => QaReview::factory()->create(['queue_entry_id' => $e->id, 'conversation_id' => $e->conversation_id, 'user_id' => $u->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('knows the resolved case status and the SLA breach stamp', function () {
    $case = SupportCase::factory()->create(['status' => 'resolved', 'sla_breached_at' => now()]);

    expect(SupportCase::STATUSES)->toContain('resolved')
        ->and(SupportCase::OPEN_STATUSES)->toBe(['new', 'in_progress'])
        ->and($case->fresh()->sla_breached_at)->not->toBeNull();
});
