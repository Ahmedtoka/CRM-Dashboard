<?php

use App\Analytics\ActivityLogger;
use App\Http\Resources\QueueEntryResource;
use App\Http\Resources\ShiftMemberResource;
use App\Inbox\OutboundService;
use App\Models\ActivityLog;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Models\UserNotification;
use App\Queue\Jobs\SendQueueMessage;
use App\Queue\QueueRouter;
use App\Queue\QueueService;
use App\Queue\WindowLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true, 'windows_per_moderator' => 1]);
});

/** A moderator on the shift since an hour ago, logged in, who may serve every platform. */
function clockDesk(Shift $shift, array $attrs = []): ShiftMember
{
    $u = User::factory()->create(['last_seen_at' => now()]);
    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $p) {
        $u->userPlatforms()->create(['platform' => $p]);
    }

    return ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'joined_at' => now()->subHour()] + $attrs);
}

/** A customer the router gives to `$m` now; her platform reply window is open (she just wrote). */
function clockWindow(ShiftMember $m): QueueEntry
{
    $e = QueueEntry::factory()->create(['enqueued_at' => now()->subMinute(), 'last_customer_message_at' => now()]);
    $e->conversation->update(['queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true, 'last_customer_message_at' => now()]);
    expect(app(QueueRouter::class)->assign($e, $m, 'test', 'live'))->toBeTrue();

    return $e->fresh();
}

/** `$seconds` after 12:00; everybody keeps sending heartbeats. */
function clockAt(int $seconds): void
{
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo')->addSeconds($seconds));
    User::query()->update(['last_seen_at' => now()]);
}

function clockCustomerWritesAt(QueueEntry $e, int $seconds): void
{
    clockAt($seconds);
    $e->conversation->forceFill(['last_customer_message_at' => now()])->save();
    app(QueueService::class)->customerMessage($e->conversation->fresh());
}

it('starts the clock when the window is delivered and stops it on her reply', function () {
    $shift = Shift::factory()->create();
    $a = clockDesk($shift);
    $e = clockWindow($a);

    expect($e->awaiting_reply_since->equalTo(now()))->toBeTrue()->and(WindowLifecycle::handOffLeft($e))->toBe(300);

    clockAt(40);
    app(OutboundService::class)->sendHuman($e->conversation, $a->user, 'معاكي');

    expect($e->fresh()->awaiting_reply_since)->toBeNull()->and(WindowLifecycle::handOffLeft($e->fresh()))->toBeNull();
});

it('starts again when the customer writes after her reply, keeps the first moment while she writes on, and never runs in the lounge', function () {
    $shift = Shift::factory()->create();
    $a = clockDesk($shift);
    $e = clockWindow($a);
    clockAt(20);
    app(OutboundService::class)->sendHuman($e->conversation, $a->user, 'تمام');

    clockCustomerWritesAt($e, 60);
    clockCustomerWritesAt($e, 90);

    expect($e->fresh()->awaiting_reply_since->equalTo(Carbon::parse('2026-10-05 12:01', 'Africa/Cairo')))->toBeTrue()
        ->and(WindowLifecycle::handOffLeft($e->fresh()))->toBe(480 - 30);   // she replied once: the later limit

    $waiting = QueueEntry::factory()->create();
    $waiting->conversation->update(['queue_entry_id' => $waiting->id]);
    app(QueueService::class)->customerMessage($waiting->conversation->fresh());
    expect($waiting->fresh()->awaiting_reply_since)->toBeNull();
});

it('apologises once per waiting period at 180 seconds and tells the moderator', function () {
    Bus::fake([SendQueueMessage::class]);
    $shift = Shift::factory()->create();
    $a = clockDesk($shift);
    $e = clockWindow($a);
    $apologies = fn () => Bus::dispatched(SendQueueMessage::class, fn ($job) => $job->scriptKey === 'queue_agent_delay_apology' && $job->entryId === $e->id);

    clockAt(179);
    app(WindowLifecycle::class)->tickReplies();
    expect($apologies())->toHaveCount(0);

    clockAt(180);
    app(WindowLifecycle::class)->tickReplies();
    clockAt(210);
    app(WindowLifecycle::class)->tickReplies();

    expect($apologies())->toHaveCount(1)
        ->and($apologies()->first()->vars)->toBe(['agent' => $a->user->name])
        ->and($e->fresh()->apology_sent_at)->not->toBeNull()
        ->and((new QueueEntryResource($e->fresh()))->resolve()['reply_overdue'])->toBeTrue()
        ->and(UserNotification::where('user_id', $a->user_id)->where('type', 'queue.reply_overdue')->count())->toBe(1);

    // She answers, the customer writes again: a new waiting period, a new apology.
    app(OutboundService::class)->sendHuman($e->conversation, $a->user, 'معلش');
    expect($e->fresh()->apology_sent_at)->toBeNull();
    clockCustomerWritesAt($e, 240);
    clockAt(240 + 180);
    app(WindowLifecycle::class)->tickReplies();
    expect($apologies())->toHaveCount(2);
});

it('hands the window to a free colleague at 300 seconds without a first reply: same ticket, top of the lounge, never back to her', function () {
    $shift = Shift::factory()->create();
    $a = clockDesk($shift);
    $e = clockWindow($a);
    $ticket = $e->ticket_no;
    $b = clockDesk($shift);   // a colleague with a free window

    clockAt(180);
    app(WindowLifecycle::class)->tickReplies();   // the apology
    clockAt(299);
    app(WindowLifecycle::class)->tickReplies();
    expect($e->fresh()->status)->toBe('active');

    clockAt(300);
    app(WindowLifecycle::class)->tickReplies();

    $old = $e->fresh();
    $new = QueueEntry::where('conversation_id', $e->conversation_id)->latest('id')->first();
    expect($old->status)->toBe('closed')->and($old->close_reason)->toBe('no_reply')->and($old->handle_seconds)->toBe(300)
        ->and($old->ticket_no)->toBe($ticket + 100000)
        ->and($new->id)->not->toBe($old->id)->and($new->ticket_no)->toBe($ticket)->and($new->priority)->toBe('returning')
        ->and($new->excluded_user_id)->toBe($a->user_id)
        ->and($new->status)->toBe('active')->and($new->assigned_user_id)->toBe($b->user_id)   // the router ran after the commit
        ->and($a->fresh()->status)->toBe('available');

    $thread = Message::where('conversation_id', $e->conversation_id);
    expect((clone $thread)->where('sender_type', 'system')->latest('id')->value('body'))
        ->toBe('اتحوّلت لزميلة تانية لأن '.$a->user->name.' ما ردّتش خلال 5 دقايق')
        ->and((clone $thread)->where('sender_type', 'bot')->where('body', 'like', '%موظفة تانية%')->count())->toBe(0);   // no «هنكمّل معاكي مع موظفة تانية»

    expect(ActivityLog::where('action', ActivityLogger::QUEUE_NO_REPLY)->first()->meta)
        ->toMatchArray(['ticket' => $ticket, 'user_id' => $a->user_id, 'minutes' => 5, 'points' => -1]);
});

it('uses the later limit once she has replied', function () {
    $shift = Shift::factory()->create();
    $a = clockDesk($shift);
    $e = clockWindow($a);
    clockDesk($shift);   // a free colleague
    clockAt(10);
    app(OutboundService::class)->sendHuman($e->conversation, $a->user, 'ثواني');
    clockCustomerWritesAt($e, 20);

    clockAt(20 + 479);
    app(WindowLifecycle::class)->tickReplies();
    expect($e->fresh()->status)->toBe('active');

    clockAt(20 + 480);
    app(WindowLifecycle::class)->tickReplies();
    expect($e->fresh()->close_reason)->toBe('no_reply');
});

it('keeps the window with her while nobody else is free, tells the leader once, and hands off on a later tick', function () {
    $leader = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $shift = Shift::factory()->create(['leader_user_id' => $leader->id]);
    ShiftMember::factory()->for($shift)->create(['user_id' => $leader->id, 'joined_at' => now()->subHour()]);   // the leader's free desk does not count
    $a = clockDesk($shift);
    $e = clockWindow($a);
    $b = clockDesk($shift);
    $busy = clockWindow($b);   // B is full (one window each)
    app(OutboundService::class)->sendHuman($busy->conversation, $b->user, 'أهلاً');   // and she answered hers

    clockAt(300);
    app(WindowLifecycle::class)->tickReplies();
    clockAt(330);
    app(WindowLifecycle::class)->tickReplies();

    expect($e->fresh()->status)->toBe('active')->and($e->fresh()->assigned_user_id)->toBe($a->user_id)
        ->and(UserNotification::where('type', 'queue.reply_overdue_leader')->where('user_id', $leader->id)->count())->toBe(1)
        ->and(UserNotification::where('type', 'queue.reply_overdue_leader')->first()->data)
        ->toMatchArray(['ticket' => $e->ticket_no, 'agent_name' => $a->user->name, 'escalation' => false, 'minutes' => 5]);

    app(WindowLifecycle::class)->close($busy->fresh(), 'inquiry', $b->user);   // B frees up
    clockAt(360);
    app(WindowLifecycle::class)->tickReplies();

    expect($e->fresh()->close_reason)->toBe('no_reply')
        ->and(QueueEntry::where('conversation_id', $e->conversation_id)->latest('id')->first()->assigned_user_id)->toBe($b->user_id);
});

it('never gives a handed-off customer back to the moderator who did not reply', function () {
    $shift = Shift::factory()->create();
    $a = clockDesk($shift);
    $e = QueueEntry::factory()->create(['priority' => 'returning', 'excluded_user_id' => $a->user_id]);

    expect(app(QueueRouter::class)->run('t'))->toBe(0)->and($e->fresh()->status)->toBe('waiting');

    $b = clockDesk($shift);
    app(QueueRouter::class)->run('t');
    expect($e->fresh()->assigned_user_id)->toBe($b->user_id);
});

it('apologises on an escalation at the leader but never hands it off; the admins hear once', function () {
    $leader = User::factory()->create(['role' => 'supervisor', 'last_seen_at' => now()]);
    $admin = User::factory()->create(['role' => 'admin']);
    $supervisor = User::factory()->create(['role' => 'supervisor']);
    $shift = Shift::factory()->create(['leader_user_id' => $leader->id]);
    $desk = ShiftMember::factory()->for($shift)->create(['user_id' => $leader->id, 'joined_at' => now()->subHour()]);
    clockDesk($shift);   // a free moderator: still no hand-off for an escalation
    $e = QueueEntry::factory()->create(['priority' => 'escalation', 'enqueued_at' => now()->subMinute()]);
    $e->conversation->update(['queue_entry_id' => $e->id, 'handler' => 'human', 'last_customer_message_at' => now()]);
    expect(app(QueueRouter::class)->assign($e, $desk, 'طابور التصعيد', 'escalation'))->toBeTrue();

    clockAt(180);
    app(WindowLifecycle::class)->tickReplies();
    clockAt(400);
    app(WindowLifecycle::class)->tickReplies();
    app(WindowLifecycle::class)->tickReplies();

    expect($e->fresh()->status)->toBe('active')->and($e->fresh()->apology_sent_at)->not->toBeNull()
        ->and(WindowLifecycle::handOffLeft($e->fresh()))->toBeNull()
        ->and(UserNotification::where('type', 'queue.reply_overdue_leader')->where('user_id', $admin->id)->count())->toBe(1)
        ->and(UserNotification::where('type', 'queue.reply_overdue_leader')->where('user_id', $supervisor->id)->count())->toBe(0)
        ->and(UserNotification::where('type', 'queue.reply_overdue_leader')->first()->data['escalation'])->toBeTrue();
});

it('does not hand off a moderator who replied after the tick read her window', function () {
    $shift = Shift::factory()->create();
    $a = clockDesk($shift);
    $e = clockWindow($a);
    clockDesk($shift);
    clockAt(300);
    $stale = QueueEntry::query()->find($e->id);
    app(OutboundService::class)->sendHuman($e->conversation, $a->user, 'آسفة، معاكي');

    expect(app(WindowLifecycle::class)->handOffNoReply($stale))->toBeNull()->and($e->fresh()->status)->toBe('active');
});

it('counts «ما ردّتش» on her desk today, and not a hand-off because she went offline', function () {
    $shift = Shift::factory()->create();
    $a = clockDesk($shift);
    clockWindow($a);
    clockDesk($shift);
    clockAt(300);
    app(WindowLifecycle::class)->tickReplies();

    app(WindowLifecycle::class)->transferAway(clockWindow($a->fresh()), 'offline');

    expect((new ShiftMemberResource($a->fresh()))->resolve()['today']['no_reply'])->toBe(1)
        ->and(ActivityLog::where('action', ActivityLogger::QUEUE_NO_REPLY)->count())->toBe(1);
});

it('never hands off (nor penalises) a moderator who is not logged in: her window follows the offline path', function () {
    Bus::fake([SendQueueMessage::class]);
    $shift = Shift::factory()->create();
    $a = clockDesk($shift);
    $e = clockWindow($a);
    clockDesk($shift);   // a free colleague

    clockAt(300);
    $a->user->forceFill(['last_seen_at' => now()->subMinutes(3)])->save();   // her heartbeat stopped
    app(WindowLifecycle::class)->tickReplies();

    expect($e->fresh()->status)->toBe('active')->and($e->fresh()->assigned_user_id)->toBe($a->user_id)
        ->and(ActivityLog::where('action', ActivityLogger::QUEUE_NO_REPLY)->count())->toBe(0)
        ->and(UserNotification::where('type', 'queue.reply_overdue_leader')->count())->toBe(0)
        ->and(Bus::dispatched(SendQueueMessage::class, fn ($job) => $job->scriptKey === 'queue_agent_delay_apology')->count())->toBe(1);   // the apology still goes out

    // Re-checked under the lock too: the tick read her online, then she dropped.
    expect(app(WindowLifecycle::class)->handOffNoReply($e->fresh()))->toBeNull()->and($e->fresh()->status)->toBe('active');

    // Online again: the next tick hands off.
    clockAt(310);
    app(WindowLifecycle::class)->tickReplies();
    expect($e->fresh()->close_reason)->toBe('no_reply');
});
