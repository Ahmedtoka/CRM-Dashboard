<?php

use App\Analytics\ActivityLogger;
use App\Bot\BotEngine;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Http\Resources\QueueEntryResource;
use App\Http\Resources\ShiftMemberResource;
use App\Inbox\ConversationActions;
use App\Inbox\InboxIngestor;
use App\Inbox\OutboundService;
use App\Models\ActivityLog;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\Events\CloseConfirmed;
use App\Queue\Events\CloseReversed;
use App\Queue\Events\WindowClosed;
use App\Queue\Jobs\ConfirmClose;
use App\Queue\Jobs\SendQueueMessage;
use App\Queue\QueueService;
use App\Queue\WaitEstimator;
use App\Queue\WindowLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** The assignee's last reply `$secondsAgo` seconds ago, after the customer's last message: the silence clock runs from it. */
function agentRepliedAgo(QueueEntry $e, int $secondsAgo = 0): void
{
    $e->forceFill(['last_agent_message_at' => now()->subSeconds($secondsAgo)])->save();
    $e->conversation->forceFill(['last_customer_message_at' => now()->subSeconds($secondsAgo + 5)])->save();
    $e->forceFill(['last_customer_message_at' => now()->subSeconds($secondsAgo + 5)])->save();
}

function activeWindow(): array
{
    $shift = Shift::query()->where('shift_key', 'morning')->first() ?? Shift::factory()->create(); // one morning shift, several desks
    $u = User::factory()->create(['last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now(), 'enqueued_at' => now()->subMinutes(2), 'last_customer_message_at' => now()]);
    $e->conversation->update(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true, 'last_customer_message_at' => now()]); // she just wrote: the platform reply window is open
    $u->userPlatforms()->create(['platform' => $e->conversation->platform->value]); // she may reply on this platform

    return [$e, $m, $u];
}

it('warns then auto-closes a silent window and gives the customer return priority', function () {
    [$e, $m] = activeWindow();
    agentRepliedAgo($e);
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
    agentRepliedAgo($e);
    Carbon::setTestNow(now()->addSeconds(200));
    app(WindowLifecycle::class)->tickSilence();
    $data = (new QueueEntryResource($e->fresh()))->resolve();
    expect($data['silence_warned'])->toBeTrue()->and($data['silence_left_seconds'])->toBe(QueueSetting::current()->silence_close_seconds - 200);
    app(QueueService::class)->customerMessage($e->conversation);
    $after = (new QueueEntryResource($e->fresh()))->resolve();
    expect($after['silence_warned'])->toBeFalse()->and($after['silence_left_seconds'])->toBeNull(); // she wrote last: no clock
});

it('does nothing on the silence tick while the queue is off', function () {
    [$e] = activeWindow();
    agentRepliedAgo($e);
    QueueSetting::current()->update(['enabled' => false]);
    Carbon::setTestNow(now()->addMinutes(30));
    app(WindowLifecycle::class)->tickSilence();
    expect($e->fresh()->status)->toBe('active')->and($e->fresh()->silence_warned_at)->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// Task 7 review fixes: silence clock from the moderator's last reply, warning message, single
// reversal, re-entry after a close, events after commit, confirm safety net.
// ---------------------------------------------------------------------------------------------

/** The customer writes: what the ingest does for the queue, without the webhook. */
function customerWrites(QueueEntry $e): void
{
    $e->conversation->forceFill(['last_customer_message_at' => now()])->save();
    app(QueueService::class)->customerMessage($e->conversation);
}

function ingestFromCustomer(string $id, string $text = 'ألو'): ?Message
{
    ChannelAccount::query()->firstWhere('external_id', 'PAGE1') ?? ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false]);

    return app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-QUEUE', 'Mona', $id, $text, CarbonImmutable::now()));
}

/** A conversation created by a real inbound message, now in an open window of a moderator on the open shift. */
function ingestedWindow(): array
{
    $c = ingestFromCustomer('first')->conversation;
    $shift = Shift::query()->where('shift_key', 'morning')->first() ?? Shift::factory()->create();
    $u = User::factory()->create(['last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now(), 'enqueued_at' => now()->subMinutes(2), 'last_customer_message_at' => now()]);
    $c->forceFill(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true])->save();
    $u->userPlatforms()->create(['platform' => $c->platform->value]);

    return [$e, $m, $u, $c->fresh()];
}

function warningsSent(): int
{
    return Message::query()->where('sender_type', 'bot')->where('body', 'like', '%هتتقفل تلقائي%')->count();
}

it('never closes on the customer while the moderator has not replied, nor right after a late reply', function () {
    [$e, $m, $u] = activeWindow();
    Carbon::setTestNow(now()->addSeconds(290)); // delivered 12:00:00, nobody answered yet
    app(WindowLifecycle::class)->tickSilence();
    expect($e->fresh()->status)->toBe('active')->and($e->fresh()->silence_warned_at)->toBeNull()
        ->and(WindowLifecycle::idleSeconds($e->fresh()))->toBeNull();

    app(OutboundService::class)->sendHuman($e->conversation, $u, 'معاكي يا فندم'); // 12:04:50, inside the SLA
    expect($e->fresh()->last_agent_message_at)->not->toBeNull()->and($e->fresh()->sla_met)->toBeTrue();
    Carbon::setTestNow(now()->addSeconds(10)); // 12:05:00: the old clock would auto-close here
    app(WindowLifecycle::class)->tickSilence();
    expect($e->fresh()->status)->toBe('active')->and($e->fresh()->silence_warned_at)->toBeNull()->and(warningsSent())->toBe(0)
        ->and(WindowLifecycle::idleSeconds($e->fresh()))->toBe(10);

    Carbon::setTestNow(now()->addMinutes(30)); // a moderator who never answers is never "customer silence"
    [$e2] = [QueueEntry::factory()->create(['assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 2, 'delivered_at' => now()->subMinutes(30)])];
    app(WindowLifecycle::class)->tickSilence();
    expect($e2->fresh()->status)->toBe('active')->and($e2->fresh()->silence_warned_at)->toBeNull();
});

it('does not warn or close while the customer wrote last', function () {
    [$e, $m, $u] = activeWindow();
    Carbon::setTestNow(now()->addSeconds(10));
    app(OutboundService::class)->sendHuman($e->conversation, $u, 'ممكن رقم الأوردر؟');
    Carbon::setTestNow(now()->addSeconds(20));
    customerWrites($e); // the ball is in the moderator's court
    Carbon::setTestNow(now()->addSeconds(600));
    app(WindowLifecycle::class)->tickSilence();
    expect($e->fresh()->status)->toBe('active')->and($e->fresh()->silence_warned_at)->toBeNull()->and(warningsSent())->toBe(0)
        ->and(WindowLifecycle::silentSince($e->fresh()))->toBeNull()
        ->and((new QueueEntryResource($e->fresh()))->resolve()['silence_left_seconds'])->toBeNull()
        ->and((new ShiftMemberResource($m->fresh()))->resolve()['windows'][0]['silence_left_seconds'])->toBeNull();
});

it('sends the silence warning once per silence period, 180 seconds after the moderator last replied', function () {
    [$e, $m, $u] = activeWindow();
    Carbon::setTestNow(now()->addSeconds(10));
    app(OutboundService::class)->sendHuman($e->conversation, $u, 'تمام يا فندم');
    Carbon::setTestNow(now()->addSeconds(179));
    app(WindowLifecycle::class)->tickSilence();
    expect(warningsSent())->toBe(0)->and($e->fresh()->silence_warned_at)->toBeNull();

    Carbon::setTestNow(now()->addSeconds(1)); // 180 s
    app(WindowLifecycle::class)->tickSilence();
    $warning = $e->conversation->messages()->where('sender_type', 'bot')->latest('id')->first();
    expect(warningsSent())->toBe(1)->and($warning->body)->toContain('بعد 2 دقيقة')->and($e->fresh()->silence_warned_at)->not->toBeNull()
        ->and((new ShiftMemberResource($m->fresh()))->resolve()['windows'][0]['silence_left_seconds'])->toBe(120);

    Carbon::setTestNow(now()->addSeconds(30)); // same silence period: not again
    app(WindowLifecycle::class)->tickSilence();
    expect(warningsSent())->toBe(1)->and($e->fresh()->status)->toBe('active');

    customerWrites($e); // she answers: the period is over
    expect($e->fresh()->silence_warned_at)->toBeNull();
    Carbon::setTestNow(now()->addSeconds(400));
    app(WindowLifecycle::class)->tickSilence();
    expect(warningsSent())->toBe(1)->and($e->fresh()->status)->toBe('active');

    app(OutboundService::class)->sendHuman($e->conversation, $u, 'اتفضلي'); // a new silence period starts
    Carbon::setTestNow(now()->addSeconds(181));
    app(WindowLifecycle::class)->tickSilence();
    expect(warningsSent())->toBe(2)->and($e->fresh()->status)->toBe('active');
});

it('dispatches the warning through the queue message job with the minutes left', function () {
    Bus::fake([SendQueueMessage::class]);
    [$e] = activeWindow();
    agentRepliedAgo($e, 200);
    app(WindowLifecycle::class)->tickSilence();
    app(WindowLifecycle::class)->tickSilence();
    Bus::assertDispatchedTimes(SendQueueMessage::class, 1);
    Bus::assertDispatched(SendQueueMessage::class, fn ($job) => $job->entryId === $e->id && $job->scriptKey === 'queue_silence_warning' && $job->vars === ['minutes' => 2]);
});

it('says nothing at the warning when the owner turned the script off, and still marks the window', function () {
    BotKnowledgeEntry::where('key', 'script.queue_silence_warning')->update(['is_active' => false]);
    [$e] = activeWindow();
    agentRepliedAgo($e, 200);
    app(WindowLifecycle::class)->tickSilence();
    expect(warningsSent())->toBe(0)->and($e->fresh()->silence_warned_at)->not->toBeNull();
});

it('auto-closes 300 seconds after the moderator last replied', function () {
    [$e, $m, $u] = activeWindow();
    Carbon::setTestNow(now()->addSeconds(100));
    app(OutboundService::class)->sendHuman($e->conversation, $u, 'تمام');
    Carbon::setTestNow(now()->addSeconds(120));
    app(OutboundService::class)->sendHuman($e->conversation, $u, 'في حاجة تانية؟'); // her LAST reply restarts the clock
    Carbon::setTestNow(now()->addSeconds(299));
    app(WindowLifecycle::class)->tickSilence();
    expect($e->fresh()->status)->toBe('active');
    Carbon::setTestNow(now()->addSeconds(1));
    app(WindowLifecycle::class)->tickSilence();
    expect($e->fresh()->status)->toBe('closed')->and($e->fresh()->close_reason)->toBe('auto')->and($e->fresh()->handle_seconds)->toBe(520)
        ->and($e->conversation->messages()->where('sender_type', 'bot')->latest('id')->first()->body)->toContain('اتقفلت المحادثة');
});

it('does not move the silence clock for a reply of someone who is not the assignee', function () {
    [$e, $m, $u] = activeWindow();
    Carbon::setTestNow(now()->addSeconds(10));
    app(WindowLifecycle::class)->agentReplied($e->conversation, User::factory()->create());
    expect($e->fresh()->last_agent_message_at)->toBeNull();
});

it('reverses a close at most once, whatever happens to the follow-up ticket', function () {
    Event::fake([CloseReversed::class, CloseConfirmed::class]);
    [$e1, $m, $u] = activeWindow();
    app(WindowLifecycle::class)->close($e1, 'inquiry', $u); // 12:00
    Carbon::setTestNow(now()->addMinutes(10));
    expect(app(QueueService::class)->customerReturned($e1->conversation->fresh()))->toBeTrue(); // E2, reverses E1
    $e2 = QueueEntry::where('conversation_id', $e1->conversation_id)->latest('id')->first();
    expect($e1->fresh()->reversed_at)->not->toBeNull()->and($e2->reopened_from_entry_id)->toBe($e1->id);

    Carbon::setTestNow(now()->addMinutes(2));
    // The router may have given E2 a window already; either way a supervisor's «حل» ends it.
    app(ConversationActions::class)->resolve($e1->conversation->fresh(), User::factory()->create(['role' => 'supervisor']));
    expect($e2->fresh()->isOpen())->toBeFalse()->and($e2->fresh()->status)->toBeIn(['cancelled', 'closed']);

    Carbon::setTestNow(now()->addMinutes(18)); // 12:30, still inside E1's 60 minutes
    expect(app(QueueService::class)->customerReturned($e1->conversation->fresh()))->toBeTrue();
    $e3 = QueueEntry::where('conversation_id', $e1->conversation_id)->latest('id')->first();
    expect($e3->id)->not->toBe($e2->id)->and($e3->reopened_from_entry_id)->toBe($e2->id); // judged on the latest ended entry, not on E1
    Event::assertDispatchedTimes(CloseReversed::class, 1);

    expect(app(WindowLifecycle::class)->reverseClose($e1->fresh()))->toBeFalse()
        ->and(app(WindowLifecycle::class)->confirm($e1->fresh()))->toBeFalse()
        ->and($e1->fresh()->confirmed_at)->toBeNull();
    Event::assertDispatchedTimes(CloseReversed::class, 1);
    Event::assertNotDispatched(CloseConfirmed::class);
});

it('never reverses a close that was already confirmed', function () {
    Event::fake([CloseReversed::class, CloseConfirmed::class]);
    [$e, $m, $u] = activeWindow();
    app(WindowLifecycle::class)->close($e, 'problem', $u);
    expect(app(WindowLifecycle::class)->confirm($e->fresh()))->toBeTrue()
        ->and(app(WindowLifecycle::class)->reverseClose($e->fresh()))->toBeFalse()
        ->and($e->fresh()->reversed_at)->toBeNull();
    Event::assertDispatchedTimes(CloseConfirmed::class, 1);
    Event::assertNotDispatched(CloseReversed::class);
});

it('puts a customer who writes after a case close back in the queue as returning, to the same moderator', function () {
    [$e, $m, $u, $c] = ingestedWindow();
    app(WindowLifecycle::class)->close($e, 'case', $u, ['case_type' => 'return']);
    $u->update(['last_seen_at' => now()->subHour()]); // offline for the router: the ticket stays in the lounge
    Carbon::setTestNow(now()->addMinutes(20));
    $msg = ingestFromCustomer('after-case', 'إمتى هيتحل؟');
    $entries = QueueEntry::where('conversation_id', $c->id)->orderBy('id')->get();
    expect($msg)->not->toBeNull()->and($entries)->toHaveCount(2);
    $new = $entries->last();
    expect($new->priority)->toBe('returning')->and($new->status)->toBe('waiting')->and($new->reserved_user_id)->toBe($u->id)
        ->and($new->reopened_from_entry_id)->toBe($e->id)->and($c->fresh()->queue_entry_id)->toBe($new->id)
        ->and($e->fresh()->reversed_at)->toBeNull() // a case close has no confirm window: nothing to reverse
        ->and($c->messages()->where('sender_type', 'bot')->latest('id')->first()->body)->toContain('رقمك '.$new->ticket_no);

    ingestFromCustomer('after-case-2', 'ألو؟'); // already waiting: no second ticket
    expect(QueueEntry::where('conversation_id', $c->id)->count())->toBe(2)
        ->and($new->fresh()->last_customer_message_at->equalTo(now()))->toBeTrue();
});

it('puts her in the live lane once the return priority has passed', function () {
    [$e, $m, $u, $c] = ingestedWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    $u->update(['last_seen_at' => now()->subDay()]);
    Carbon::setTestNow(now()->addMinutes(QueueSetting::current()->return_priority_minutes + 1)); // 14:01, shift still open
    app(WindowLifecycle::class)->confirmDue();
    ingestFromCustomer('later', 'عندي سؤال تاني');
    $new = QueueEntry::where('conversation_id', $c->id)->latest('id')->first();
    expect($new->id)->not->toBe($e->id)->and($new->priority)->toBe('live')->and($new->reserved_user_id)->toBeNull()
        ->and($new->reopened_from_entry_id)->toBeNull()->and($new->reopen_count)->toBe(0)
        ->and($c->fresh()->needs_human)->toBeTrue()
        ->and($c->messages()->where('sender_type', 'bot')->latest('id')->first()->body)->toContain('رقمك في الدور '.$new->ticket_no);
});

it('follows the overnight path when she writes after a close outside shift hours', function () {
    [$e, $m, $u, $c] = ingestedWindow();
    app(WindowLifecycle::class)->close($e, 'case', $u);
    Shift::query()->update(['status' => 'closed']);
    Carbon::setTestNow(now()->addMinutes(30));
    ingestFromCustomer('night', 'ألو');
    $new = QueueEntry::where('conversation_id', $c->id)->latest('id')->first();
    expect($new->id)->not->toBe($e->id)->and($new->priority)->toBe('overnight')
        ->and($c->messages()->where('sender_type', 'bot')->latest('id')->first()->body)->toContain('خارج مواعيد العمل');
});

it('creates nothing after a close when the queue is off, when it takes no handovers, or when the bot handles her', function () {
    [$e, $m, $u, $c] = ingestedWindow();
    app(WindowLifecycle::class)->close($e, 'case', $u);
    Carbon::setTestNow(now()->addMinutes(20));

    QueueSetting::current()->update(['enabled' => false]);
    $msg = ingestFromCustomer('off', 'ألو');
    expect($msg)->not->toBeNull()->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1)
        ->and($c->fresh()->queue_entry_id)->toBe($e->id)->and($c->messages()->where('sender_type', 'bot')->count())->toBe(1); // only the case message

    QueueSetting::current()->update(['enabled' => true, 'night_message_enabled' => false]);
    Shift::query()->update(['status' => 'closed']); // no shift, no night message: the queue takes no handovers
    ingestFromCustomer('no-handover', 'ألو');
    expect(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1);

    Shift::query()->update(['status' => 'open']);
    $c->forceFill(['handler' => 'bot'])->save();
    expect(app(QueueService::class)->customerReturned($c->fresh()))->toBeFalse()
        ->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1);
});

it('leaves a human-handled conversation that never was in the queue alone', function () {
    Shift::factory()->create();
    $c = ingestFromCustomer('first')->conversation;
    $c->forceFill(['handler' => 'human'])->save();
    ingestFromCustomer('second', 'ألو');
    expect(QueueEntry::where('conversation_id', $c->id)->count())->toBe(0);
});

it('closes the window although a WindowClosed listener throws', function () {
    Event::listen(WindowClosed::class, fn () => throw new RuntimeException('score keeper is broken'));
    [$e, $m, $u] = activeWindow();
    $closed = app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    expect($closed->status)->toBe('closed')->and($e->fresh()->status)->toBe('closed')->and($m->fresh()->status)->toBe('available');

    [$e2, $m2, $u2] = activeWindow();
    app(ConversationActions::class)->resolve($e2->conversation, $u2);
    expect($e2->fresh()->close_reason)->toBe('resolved_elsewhere')->and($e2->conversation->fresh()->status->value)->toBe('resolved');

    [$e3, $m3, $u3] = activeWindow();
    app(BotEngine::class)->returnToBot($e3->conversation, $u3);
    expect($e3->fresh()->close_reason)->toBe('cancelled')->and($e3->conversation->fresh()->handler->value)->toBe('bot');

    [$e4] = activeWindow();
    agentRepliedAgo($e4, 301);
    app(WindowLifecycle::class)->tickSilence();
    expect($e4->fresh()->close_reason)->toBe('auto');
});

it('keeps the inbound message and re-queues her although a CloseReversed listener throws', function () {
    Event::listen(CloseReversed::class, fn () => throw new RuntimeException('score keeper is broken'));
    Event::listen(CloseConfirmed::class, fn () => throw new RuntimeException('score keeper is broken'));
    [$e, $m, $u, $c] = ingestedWindow();
    app(WindowLifecycle::class)->close($e, 'problem', $u);
    Carbon::setTestNow(now()->addMinutes(20));
    $msg = ingestFromCustomer('back', 'لسه المشكلة موجودة');
    expect($msg)->not->toBeNull()->and(Message::whereKey($msg->id)->exists())->toBeTrue()
        ->and($e->fresh()->reversed_at)->not->toBeNull()
        ->and(QueueEntry::where('conversation_id', $c->id)->where('reopened_from_entry_id', $e->id)->count())->toBe(1);

    [$e2, $m2, $u2] = activeWindow();
    app(WindowLifecycle::class)->close($e2, 'inquiry', $u2);
    expect(app(WindowLifecycle::class)->confirm($e2->fresh()))->toBeTrue()->and($e2->fresh()->confirmed_at)->not->toBeNull();
});

it('hands the events over only once the surrounding transaction has committed', function () {
    Event::fake([WindowClosed::class]);
    [$e, $m, $u] = activeWindow();
    DB::transaction(function () use ($e, $u) {
        app(WindowLifecycle::class)->close($e, 'inquiry', $u);
        Event::assertNotDispatched(WindowClosed::class);
    });
    Event::assertDispatchedTimes(WindowClosed::class, 1);
});

it('confirms only the due manual closes in the safety net', function () {
    Event::fake([CloseConfirmed::class]);
    $life = app(WindowLifecycle::class);
    [$due, , $u] = activeWindow();
    $life->close($due, 'inquiry', $u);
    [$reversed, , $u2] = activeWindow();
    $life->close($reversed, 'problem', $u2);
    [$case, , $u3] = activeWindow();
    $life->close($case, 'case', $u3);
    [$auto] = activeWindow();
    agentRepliedAgo($auto, 301);
    $life->tickSilence();
    expect($auto->fresh()->close_reason)->toBe('auto');

    Carbon::setTestNow(now()->addMinutes(30));
    app(QueueService::class)->customerReturned($reversed->conversation->fresh());
    [$fresh, , $u4] = activeWindow();
    $life->close($fresh, 'inquiry', $u4); // closed 12:30, due 13:30
    expect($life->confirmDue())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(30)); // 13:00
    expect($life->confirmDue())->toBe(1)
        ->and($due->fresh()->confirmed_at)->not->toBeNull()
        ->and($reversed->fresh()->confirmed_at)->toBeNull()
        ->and($case->fresh()->confirmed_at)->toBeNull()
        ->and($auto->fresh()->confirmed_at)->toBeNull()
        ->and($fresh->fresh()->confirmed_at)->toBeNull()
        ->and($life->confirmDue())->toBe(0);
    Event::assertDispatchedTimes(CloseConfirmed::class, 1);
});

it('releases an early confirm job for the remaining time and confirms when it is due', function () {
    [$e, $m, $u] = activeWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    $token = $e->fresh()->closed_at->toIso8601String();
    Carbon::setTestNow(now()->addMinutes(15));

    $job = (new ConfirmClose($e->id, $token))->withFakeQueueInteractions();
    $job->handle(app(WindowLifecycle::class));
    $job->assertReleased(delay: 45 * 60);
    expect($e->fresh()->confirmed_at)->toBeNull()
        ->and($job->tries)->toBe(0)
        ->and($job->retryUntil()->getTimestamp())->toBe($e->fresh()->closed_at->copy()->addMinutes(60)->addDay()->getTimestamp());

    Carbon::setTestNow(now()->addMinutes(45));
    $job = (new ConfirmClose($e->id, $token))->withFakeQueueInteractions();
    $job->handle(app(WindowLifecycle::class));
    $job->assertNotReleased();
    expect($e->fresh()->confirmed_at)->not->toBeNull();
});

it('survives a worker with --tries=1: released early, confirmed by the same queued job later', function () {
    config(['queue.default' => 'database']);
    [$e, $m, $u] = activeWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u); // pushed with a 60 minutes delay
    expect(DB::table('jobs')->where('queue', 'bot')->count())->toBe(1);

    QueueSetting::current()->update(['close_confirm_minutes' => 90]); // the owner made the window longer
    Carbon::setTestNow(now()->addMinutes(60));
    Artisan::call('queue:work', ['--once' => true, '--queue' => 'bot', '--tries' => 1]);
    expect($e->fresh()->confirmed_at)->toBeNull()->and(DB::table('jobs')->where('queue', 'bot')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(30));
    Artisan::call('queue:work', ['--once' => true, '--queue' => 'bot', '--tries' => 1]);
    expect($e->fresh()->confirmed_at)->not->toBeNull()->and(DB::table('jobs')->where('queue', 'bot')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('gives the window back when a test conversation is reset', function () {
    [$e, $m, $u] = activeWindow();
    app(ConversationActions::class)->reset($e->conversation, $u);
    $c = $e->conversation->fresh();
    expect($e->fresh()->status)->toBe('closed')->and($e->fresh()->close_reason)->toBe('cancelled')->and($m->fresh()->status)->toBe('available')
        ->and($c->assignee_id)->toBeNull()->and($c->queue_entry_id)->toBeNull()->and($c->return_priority_until)->toBeNull();
});

it('averages the handle time over really handled windows only', function () {
    foreach (['inquiry', 'problem', 'case', 'auto', 'inquiry'] as $i => $reason) {
        QueueEntry::factory()->create(['status' => 'closed', 'close_reason' => $reason, 'handle_seconds' => 200, 'closed_at' => now()->subMinutes($i + 1)]);
    }
    foreach (['transfer', 'cancelled', 'resolved_elsewhere'] as $reason) {
        QueueEntry::factory()->create(['status' => 'closed', 'close_reason' => $reason, 'handle_seconds' => 5000, 'closed_at' => now()]);
    }
    expect(app(WaitEstimator::class)->avgHandleSeconds())->toBe(200);
});

it('logs a ticket that leaves the lounge cancelled', function () {
    $u = User::factory()->create();
    $w = QueueEntry::factory()->create(['status' => 'waiting']);
    $w->conversation->update(['queue_entry_id' => $w->id]);
    app(WindowLifecycle::class)->close($w, 'cancelled', $u);
    $log = ActivityLog::where('action', ActivityLogger::QUEUE_CLOSE)->where('conversation_id', $w->conversation_id)->first();
    expect($w->fresh()->status)->toBe('cancelled')->and($log)->not->toBeNull()->and($log->meta['reason'])->toBe('cancelled')->and($log->user_id)->toBe($u->id);
});
