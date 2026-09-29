<?php

use App\Bot\BotEngine;
use App\Http\Resources\QueueEntryResource;
use App\Inbox\ConversationActions;
use App\Inbox\OutboundService;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\Events\CloseConfirmed;
use App\Queue\Events\CloseReversed;
use App\Queue\Events\WindowClosed;
use App\Queue\Jobs\ConfirmClose;
use App\Queue\QueueService;
use App\Queue\WindowLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

function activeWindow(): array
{
    $shift = Shift::factory()->create();
    $u = User::factory()->create(['last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now(), 'enqueued_at' => now()->subMinutes(2), 'last_customer_message_at' => now()]);
    $e->conversation->update(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true, 'last_customer_message_at' => now()]); // she just wrote: the platform reply window is open
    $u->userPlatforms()->create(['platform' => $e->conversation->platform->value]); // she may reply on this platform

    return [$e, $m, $u];
}

it('warns then auto-closes a silent window and gives the customer return priority', function () {
    [$e, $m] = activeWindow();
    Carbon::setTestNow(now()->addSeconds(181));
    app(WindowLifecycle::class)->tickSilence();
    expect($e->fresh()->silence_warned_at)->not->toBeNull()->and($e->fresh()->status)->toBe('active');
    Carbon::setTestNow(now()->addSeconds(120));
    app(WindowLifecycle::class)->tickSilence();
    $e->refresh();
    expect($e->status)->toBe('closed')->and($e->close_reason)->toBe('auto')->and($e->conversation->fresh()->assignee_id)->toBeNull()
        ->and($e->conversation->fresh()->return_priority_until->gt(now()))->toBeTrue()->and($m->fresh()->status)->toBe('available');
});

it('records first reply, SLA and handle time on a manual close', function () {
    [$e, $m, $u] = activeWindow();
    Carbon::setTestNow(now()->addSeconds(40));
    app(OutboundService::class)->sendHuman($e->conversation, $u, 'معاكي');
    expect($e->fresh()->first_reply_at)->not->toBeNull()->and($e->fresh()->sla_met)->toBeTrue();
    Carbon::setTestNow(now()->addMinutes(3));
    app(WindowLifecycle::class)->close($e->fresh(), 'inquiry', $u);
    $e->refresh();
    expect($e->close_reason)->toBe('inquiry')->and($e->handle_seconds)->toBe(220)->and($e->confirmed_at)->toBeNull()->and($e->conversation->fresh()->assignee_id)->toBeNull();
});

it('reopens with returning priority to the same member when the customer writes within the confirm window', function () {
    [$e, $m, $u] = activeWindow();
    app(WindowLifecycle::class)->close($e, 'problem', $u);
    Carbon::setTestNow(now()->addMinutes(20));
    expect(app(QueueService::class)->customerReturned($e->conversation->fresh()))->toBeTrue();
    $new = QueueEntry::where('conversation_id', $e->conversation_id)->latest('id')->first();
    expect($new->priority)->toBe('returning')->and($new->reserved_user_id)->toBe($u->id)->and($new->reopened_from_entry_id)->toBe($e->id)->and($e->fresh()->reopen_count)->toBe(0)->and($new->reopen_count)->toBe(1);
});

it('escalates to a new entry and frees the window immediately', function () {
    [$e, $m, $u] = activeWindow();
    $new = app(WindowLifecycle::class)->escalate($e, $u);
    expect($e->fresh()->close_reason)->toBe('escalation')->and($new->priority)->toBe('escalation')->and($new->ticket_no)->toBe($e->ticket_no)->and($m->fresh()->openEntries()->count())->toBe(0);
});

it('opens a support case on a case close', function () {
    [$e, $m, $u] = activeWindow();
    app(WindowLifecycle::class)->close($e, 'case', $u, ['case_type' => 'return']);
    expect($e->fresh()->support_case_id)->not->toBeNull()->and($e->fresh()->conversation->cases()->count())->toBe(1)
        ->and($e->fresh()->conversation->cases()->first()->sla_due_at)->not->toBeNull();
});

it('closes the entry as resolved_elsewhere when the conversation is resolved from the inbox', function () {
    [$e, $m, $u] = activeWindow();
    app(ConversationActions::class)->resolve($e->conversation, $u);
    expect($e->fresh()->close_reason)->toBe('resolved_elsewhere');
});

it('does not mark the first reply twice nor for someone who is not the assignee', function () {
    [$e, $m, $u] = activeWindow();
    $other = User::factory()->create();
    app(WindowLifecycle::class)->markFirstReply($e->conversation, $other);
    expect($e->fresh()->first_reply_at)->toBeNull();
    app(WindowLifecycle::class)->markFirstReply($e->conversation, $u);
    $first = $e->fresh()->first_reply_at;
    Carbon::setTestNow(now()->addSeconds(30));
    app(OutboundService::class)->sendHuman($e->conversation, $u, 'تمام');
    expect($first)->not->toBeNull()->and($e->fresh()->first_reply_at->equalTo($first))->toBeTrue();
});

it('misses the SLA when the first reply comes late', function () {
    [$e, $m, $u] = activeWindow();
    Carbon::setTestNow(now()->addSeconds(QueueSetting::current()->sla_first_reply_seconds));
    app(OutboundService::class)->sendHuman($e->conversation, $u, 'معاكي');
    expect($e->fresh()->first_reply_at)->not->toBeNull()->and($e->fresh()->sla_met)->toBeFalse();
});

it('keeps the member busy while the same user still has an open window on another shift row', function () {
    [$e, $m, $u] = activeWindow();
    $m2 = ShiftMember::factory()->for(Shift::factory()->create(['shift_key' => 'evening']))->create(['user_id' => $u->id, 'status' => 'busy']);
    $e2 = QueueEntry::factory()->create(['shift_id' => $m2->shift_id, 'shift_member_id' => $m2->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 2, 'delivered_at' => now(), 'last_customer_message_at' => now()]);
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    expect($m->fresh()->status)->toBe('busy');
    app(WindowLifecycle::class)->close($e2, 'inquiry', $u);
    expect($m2->fresh()->status)->toBe('available');
});

it('leaves a member on break as she is and closes a window that has no member row', function () {
    [$e, $m, $u] = activeWindow();
    $m->update(['status' => 'break']);
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    expect($m->fresh()->status)->toBe('break');

    $e2 = QueueEntry::factory()->create(['assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now()]);
    expect(app(WindowLifecycle::class)->close($e2, 'problem', $u)->status)->toBe('closed');
});

it('confirms a close only once the confirm window has passed without a reopen', function () {
    Event::fake([CloseConfirmed::class, WindowClosed::class]);
    [$e, $m, $u] = activeWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    $token = $e->fresh()->closed_at->toIso8601String();
    Event::assertDispatched(WindowClosed::class, fn ($ev) => $ev->entry->id === $e->id && $ev->reason === 'inquiry');

    (new ConfirmClose($e->id, $token))->handle(app(WindowLifecycle::class));
    expect($e->fresh()->confirmed_at)->toBeNull();

    Carbon::setTestNow(now()->addMinutes(QueueSetting::current()->close_confirm_minutes));
    (new ConfirmClose($e->id, 'another-close'))->handle(app(WindowLifecycle::class));
    expect($e->fresh()->confirmed_at)->toBeNull();
    (new ConfirmClose($e->id, $token))->handle(app(WindowLifecycle::class));
    expect($e->fresh()->confirmed_at)->not->toBeNull();
    Event::assertDispatchedTimes(CloseConfirmed::class, 1);
});

it('never confirms a close the customer reopened, and reverses it', function () {
    Event::fake([CloseConfirmed::class, CloseReversed::class]);
    [$e, $m, $u] = activeWindow();
    app(WindowLifecycle::class)->close($e, 'problem', $u);
    Carbon::setTestNow(now()->addMinutes(20));
    app(QueueService::class)->customerReturned($e->conversation->fresh());
    Event::assertDispatched(CloseReversed::class, fn ($ev) => $ev->entry->id === $e->id);
    Carbon::setTestNow(now()->addMinutes(60));
    (new ConfirmClose($e->id, $e->fresh()->closed_at->toIso8601String()))->handle(app(WindowLifecycle::class));
    expect($e->fresh()->confirmed_at)->toBeNull();
    Event::assertNotDispatched(CloseConfirmed::class);
});

it('confirms at once when the confirm window is switched off', function () {
    QueueSetting::current()->update(['close_confirm_minutes' => 0]);
    [$e, $m, $u] = activeWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    expect($e->fresh()->confirmed_at)->not->toBeNull();
});

it('cancels the window when the conversation goes back to the bot, and a waiting ticket leaves the lounge', function () {
    [$e, $m, $u] = activeWindow();
    app(BotEngine::class)->returnToBot($e->conversation, $u);
    expect($e->fresh()->status)->toBe('closed')->and($e->fresh()->close_reason)->toBe('cancelled')->and($m->fresh()->status)->toBe('available');

    $w = QueueEntry::factory()->create(['status' => 'waiting']);
    $w->conversation->update(['queue_entry_id' => $w->id]);
    app(ConversationActions::class)->resolve($w->conversation, $u);
    expect($w->fresh()->status)->toBe('cancelled')->and($w->fresh()->close_reason)->toBe('resolved_elsewhere');
});

it('transfers away keeping the ticket and frees the desk', function () {
    [$e, $m, $u] = activeWindow();
    $ticket = $e->ticket_no;
    $u->update(['last_seen_at' => now()->subHour()]); // she went offline: the router must not hand the customer back to her
    $new = app(WindowLifecycle::class)->transferAway($e, 'offline');
    expect($new->ticket_no)->toBe($ticket)->and($new->priority)->toBe('returning')->and($new->status)->toBe('waiting')
        ->and($e->fresh()->close_reason)->toBe('transfer')->and($e->fresh()->ticket_no)->not->toBe($ticket)
        ->and($e->conversation->fresh()->queue_entry_id)->toBe($new->id)->and($m->fresh()->status)->toBe('available');
    expect(app(WindowLifecycle::class)->escalate($e->fresh(), $u))->toBeNull();
});

it('exposes the silence countdown and warning on the entry resource', function () {
    [$e] = activeWindow();
    Carbon::setTestNow(now()->addSeconds(200));
    app(WindowLifecycle::class)->tickSilence();
    $data = (new QueueEntryResource($e->fresh()))->resolve();
    expect($data['silence_warned'])->toBeTrue()->and($data['silence_left_seconds'])->toBe(QueueSetting::current()->silence_close_seconds - 200);
    app(QueueService::class)->customerMessage($e->conversation);
    expect((new QueueEntryResource($e->fresh()))->resolve()['silence_warned'])->toBeFalse();
});

it('does nothing on the silence tick while the queue is off', function () {
    [$e] = activeWindow();
    QueueSetting::current()->update(['enabled' => false]);
    Carbon::setTestNow(now()->addMinutes(30));
    app(WindowLifecycle::class)->tickSilence();
    expect($e->fresh()->status)->toBe('active')->and($e->fresh()->silence_warned_at)->toBeNull();
});
