<?php

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Inbox\Outcomes\Outcome;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\WindowLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
    Bus::fake();
});

/** @return array{0: QueueEntry, 1: User} */
function s3OpenWindow(array $entry = []): array
{
    $shift = Shift::factory()->create();
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create($entry + ['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now(), 'enqueued_at' => now()->subMinute()]);
    $e->conversation->update(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true]);
    Message::factory()->for($e->conversation)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer]);

    return [$e, $u];
}

it('ends the episode on her final close with the picked outcome', function () {
    [$e, $u] = s3OpenWindow();

    app(WindowLifecycle::class)->close($e, 'inquiry', $u, ['outcome' => Outcome::SizeOut]);

    $row = ConversationOutcome::sole();
    expect($row->outcome)->toBe('size_out')->and($row->source)->toBe('agent')->and($row->set_by_id)->toBe($u->id)
        ->and($row->queue_entry_id)->toBe($e->id)->and($row->ended_by)->toBe('close')->and($row->reached_agent)->toBeTrue();
});

it('ends the episode as no_answer when the silence closes the window', function () {
    [$e] = s3OpenWindow();
    $e->forceFill(['last_agent_message_at' => now()->subSeconds(400), 'last_customer_message_at' => now()->subSeconds(405)])->save();
    $e->conversation->forceFill(['last_customer_message_at' => now()->subSeconds(405)])->save();

    app(WindowLifecycle::class)->tickSilence();

    expect($e->fresh()->close_reason)->toBe('auto')
        ->and(ConversationOutcome::sole()->outcome)->toBe('no_answer')
        ->and(ConversationOutcome::sole()->ended_by)->toBe('auto_close');
});

it('does not end the episode on an escalation, a transfer or a cancel', function () {
    [$e, $u] = s3OpenWindow();
    app(WindowLifecycle::class)->escalate($e, $u);
    expect(ConversationOutcome::count())->toBe(0);
});
