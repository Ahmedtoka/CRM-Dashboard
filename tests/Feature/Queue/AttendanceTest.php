<?php

use App\Models\Conversation;
use App\Models\QueueAttendanceEvent;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Models\UserNotification;
use App\Queue\AttendanceRefused;
use App\Queue\Data\HandoverContext;
use App\Queue\Events\QueueMemberUpdated;
use App\Queue\QueueRouter;
use App\Queue\QueueService;
use App\Queue\ShiftService;
use App\Queue\WindowLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** A moderator allowed on every platform, logged in now. */
function attModerator(array $attrs = []): User
{
    $u = User::factory()->create($attrs + ['role' => 'moderator', 'last_seen_at' => now()]);

    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $platform) {
        $u->userPlatforms()->create(['platform' => $platform]);
    }

    return $u;
}

/** One more open window of hers on this desk. */
function attWindow(ShiftMember $m, array $attrs = []): QueueEntry
{
    $taken = QueueEntry::query()->where('assigned_user_id', $m->user_id)->whereIn('status', ['called', 'active'])->count();
    $e = QueueEntry::factory()->create($attrs + [
        'shift_id' => $m->shift_id, 'shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active',
        'window_no' => $taken + 1, 'called_at' => now(), 'delivered_at' => now(),
    ]);
    $e->conversation->update(['assignee_id' => $m->user_id, 'assigned_at' => now(), 'queue_entry_id' => $e->id, 'handler' => 'human']);
    $m->update(['status' => 'busy']);

    return $e;
}

/** Her attendance log, oldest first: `event`, or `event:by_user_id` when somebody did it for her. @return list<string> */
function attLog(User $u): array
{
    return QueueAttendanceEvent::query()->where('user_id', $u->id)->orderBy('at')->orderBy('id')->get()
        ->map(fn (QueueAttendanceEvent $ev) => $ev->event.($ev->by_user_id !== null ? ':'.$ev->by_user_id : ''))->all();
}

/** The key of the refusal a call throws, or null when it went through. */
function attRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (AttendanceRefused $e) {
        return $e->key;
    }

    return null;
}

it('has the attendance log and the two new desk columns', function () {
    expect(Schema::hasColumns('queue_attendance_events', ['user_id', 'shift_id', 'business_date', 'event', 'at', 'by_user_id']))->toBeTrue()
        ->and(Schema::hasColumns('shift_members', ['requested_by_id', 'break_overrun_alerted_at']))->toBeTrue()
        ->and(ShiftMember::STATUSES)->toContain('checking_out');
});

// ───── «بدأت شغل» ─────

it('checks her in only while a shift is open, and says when the next one starts', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:40', 'Africa/Cairo'));
    $u = attModerator();
    $svc = app(ShiftService::class);

    $refused = null;
    try {
        $svc->checkIn($u);
    } catch (AttendanceRefused $e) {
        $refused = $e;
    }

    expect($refused?->key)->toBe('shift_not_open')->and($refused?->status)->toBe(409)->and($refused?->replace)->toBe(['time' => '10:00'])
        ->and(ShiftMember::count())->toBe(0)->and(attLog($u))->toBe([]);

    // Ten o'clock: the tick has not opened the shift yet; her click opens it.
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:10', 'Africa/Cairo'));
    $m = $svc->checkIn($u);

    expect($m->status)->toBe('available')->and($m->shift->shift_key)->toBe('morning')->and($m->shift->status)->toBe('open')
        ->and(attLog($u))->toBe(['in'])
        ->and(QueueAttendanceEvent::query()->first()->business_date)->toBe('2026-10-05')
        ->and($u->fresh()->last_seen_at->equalTo(now()))->toBeTrue(); // pressing it proves she is here
});

it('refuses a check-in without a platform, to a deactivated account, and while the queue is off', function () {
    Shift::factory()->create();
    $svc = app(ShiftService::class);
    $bare = User::factory()->create(['role' => 'supervisor', 'last_seen_at' => now()]); // the role alone is no platform
    $gone = attModerator();
    $gone->forceFill(['is_active' => false])->save();

    expect(attRefusal(fn () => $svc->checkIn($bare)))->toBe('no_platforms')
        ->and(attRefusal(fn () => $svc->checkIn($gone)))->toBe('no_platforms');

    QueueSetting::current()->update(['enabled' => false]);
    expect(attRefusal(fn () => $svc->checkIn(attModerator())))->toBe('disabled')
        ->and(ShiftMember::count())->toBe(0);
});

it('reactivates her row when she comes back the same shift, and the router gives her the lounge', function () {
    Shift::factory()->create();
    $u = attModerator();
    $svc = app(ShiftService::class);
    $m = $svc->checkIn($u);
    $joined = $m->joined_at;
    expect($svc->checkOut($m))->toBe('left');

    $waiting = QueueEntry::factory()->create(['enqueued_at' => now()->subMinute()]);
    Carbon::setTestNow(now()->addMinutes(20));
    $again = $svc->checkIn($u);

    expect($again->id)->toBe($m->id)->and(ShiftMember::count())->toBe(1)
        ->and($again->joined_at->equalTo($joined))->toBeTrue()
        ->and($waiting->fresh()->assigned_user_id)->toBe($u->id)
        ->and($again->fresh()->status)->toBe('busy')
        ->and(attLog($u))->toBe(['in', 'out', 'in']);
});

it('changes nothing when she presses «بدأت شغل» twice', function () {
    Shift::factory()->create();
    $u = attModerator();
    $svc = app(ShiftService::class);
    $first = $svc->checkIn($u);
    $svc->setStatus($first, 'break', $u);

    $second = $svc->checkIn($u);

    expect($second->id)->toBe($first->id)->and($second->status)->toBe('break')->and(attLog($u))->toBe(['in', 'break']);
});

// ───── «استراحة» / «رجعت» ─────

it('starts a break at once, never ends it by itself, and tells the leader once per break when it runs over', function () {
    $leader = attModerator();
    Shift::factory()->create(['leader_user_id' => $leader->id]);
    $u = attModerator();
    $svc = app(ShiftService::class);
    $m = $svc->checkIn($u);

    $svc->setStatus($m, 'break', $u);
    expect($m->fresh()->status)->toBe('break')->and($m->fresh()->break_ends_at->equalTo(now()->addMinutes(30)))->toBeTrue();

    Carbon::setTestNow(now()->addMinutes(29));
    $svc->tickMembers();
    expect(UserNotification::where('type', 'queue.break_overrun')->count())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(3)); // 32 minutes: past the 30
    $svc->tickMembers();
    $svc->tickMembers();

    $sent = UserNotification::where('type', 'queue.break_overrun')->get();
    expect($m->fresh()->status)->toBe('break')
        ->and($sent->pluck('user_id')->all())->toBe([$leader->id])
        ->and($sent->first()->data)->toMatchArray(['member_id' => $m->id, 'user_id' => $u->id, 'name' => $u->name, 'minutes' => 30]);

    $svc->setStatus($m, 'available', $u);
    expect($m->fresh()->status)->toBe('available')->and(attLog($u))->toBe(['in', 'break', 'back']);

    // The next break is a new one: it may be reported again.
    $svc->setStatus($m, 'break', $u);
    Carbon::setTestNow(now()->addMinutes(31));
    $svc->tickMembers();
    expect(UserNotification::where('type', 'queue.break_overrun')->count())->toBe(2);
});

it('tells the supervisors and admins, not herself, when the leader\'s own break runs over', function () {
    $sup = User::factory()->create(['role' => 'supervisor']);
    $leader = attModerator(['role' => 'supervisor']);
    Shift::factory()->create(['leader_user_id' => $leader->id]);
    $svc = app(ShiftService::class);
    $m = $svc->checkIn($leader);
    $svc->setStatus($m, 'break', $leader);

    Carbon::setTestNow(now()->addMinutes(31));
    $svc->tickMembers();

    expect(UserNotification::where('type', 'queue.break_overrun')->pluck('user_id')->all())->toBe([$sup->id]);
});

it('holds a break asked with an open window until the window closes, recording who asked', function () {
    $shift = Shift::factory()->create();
    $leader = User::query()->findOrFail($shift->leader_user_id);
    $u = attModerator();
    $svc = app(ShiftService::class);
    $m = $svc->checkIn($u);
    $e = attWindow($m);

    $svc->setStatus($m, 'break', $leader);
    expect($m->fresh()->status)->toBe('pending_break')->and(attLog($u))->toBe(['in']);

    app(WindowLifecycle::class)->close($e, 'inquiry', $u);

    expect($m->fresh()->status)->toBe('break')->and(attLog($u))->toBe(['in', 'break:'.$leader->id]);
});

// ───── «خروج» and «رجّعي شبابيكي للصالة» ─────

it('checks her out at once without windows, and after her last window with them', function () {
    Shift::factory()->create();
    $svc = app(ShiftService::class);
    $free = $svc->checkIn(attModerator());

    expect($svc->checkOut($free))->toBe('left')->and($free->fresh()->left_at)->not->toBeNull();

    $u = attModerator();
    $m = $svc->checkIn($u);
    $e = attWindow($m);
    $waiting = QueueEntry::factory()->create();

    expect($svc->checkOut($m))->toBe('checking_out')
        ->and($m->fresh()->status)->toBe('checking_out')->and(attLog($u))->toBe(['in'])
        ->and(app(QueueRouter::class)->run('t'))->toBe(0)
        ->and($waiting->fresh()->status)->toBe('waiting'); // no new chats while she is on her way out

    $svc->setStatus($m, 'break', $u);
    expect($m->fresh()->status)->toBe('checking_out');

    app(WindowLifecycle::class)->close($e, 'problem', $u);

    expect($m->fresh()->status)->toBe('left')->and(attLog($u))->toBe(['in', 'out']);
});

it('hands every open window back to the lounge with its ticket at the top, then she leaves, with no penalty', function () {
    Shift::factory()->create();
    $svc = app(ShiftService::class);
    $u = attModerator();
    $m = $svc->checkIn($u);
    $a = attWindow($m, ['ticket_no' => 31]);
    $b = attWindow($m, ['ticket_no' => 32]);

    expect($svc->handBack($m))->toBe(0); // only while she is checking out

    $svc->checkOut($m);
    expect($svc->handBack($m->fresh()))->toBe(2);

    $lounge = QueueEntry::query()->where('status', 'waiting')->orderBy('ticket_no')->get();
    expect($lounge->pluck('ticket_no')->all())->toBe([31, 32])
        ->and($lounge->pluck('priority')->unique()->values()->all())->toBe(['returning'])
        ->and([$a->fresh()->close_reason, $b->fresh()->close_reason])->toBe(['transfer', 'transfer'])
        ->and(QueueEntry::query()->where('close_reason', 'no_reply')->count())->toBe(0)
        ->and($m->fresh()->status)->toBe('left')
        ->and(attLog($u))->toBe(['in', 'out']);
});

it('checks her out when she logs out of the CRM', function () {
    Shift::factory()->create();
    $u = attModerator();
    $m = app(ShiftService::class)->checkIn($u);

    $this->actingAs($u)->post('/logout');

    expect($m->fresh()->status)->toBe('left')->and(attLog($u))->toBe(['in', 'out']);
});

// ───── automatic check-out ─────

it('checks everybody out when the shift closes, and her windows stay with her', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 17:50', 'Africa/Cairo'));
    app(ShiftService::class)->transition();
    $u = attModerator();
    $m = app(ShiftService::class)->checkIn($u);
    $e = attWindow($m);

    Carbon::setTestNow(Carbon::parse('2026-10-05 18:00:30', 'Africa/Cairo'));
    app(ShiftService::class)->transition();

    expect($m->fresh()->status)->toBe('left')->and(attLog($u))->toBe(['in', 'auto_out'])
        ->and($e->fresh()->status)->toBe('active')
        ->and(Shift::where('shift_key', 'evening')->first()->status)->toBe('open')
        ->and(Shift::where('shift_key', 'evening')->first()->members()->count())->toBe(0);
});

it('checks her out after 10 minutes without a heartbeat; her windows went back after 5', function () {
    Shift::factory()->create();
    $svc = app(ShiftService::class);
    $u = attModerator();
    $m = $svc->checkIn($u);
    $e = attWindow($m);
    // Two colleagues who stay online, so the mass-offline safeguard does not hold her.
    $others = [attModerator(), attModerator()];
    foreach ($others as $o) {
        $svc->checkIn($o);
    }
    $stay = fn () => User::query()->whereIn('id', array_map(fn (User $o) => $o->id, $others))->update(['last_seen_at' => now()]);

    Carbon::setTestNow(now()->addMinutes(6));
    $stay();
    $svc->tickMembers();
    expect($e->fresh()->close_reason)->toBe('transfer')->and($m->fresh()->status)->toBe('offline')->and(attLog($u))->toBe(['in']);

    Carbon::setTestNow(now()->addMinutes(4)); // ten minutes without her heartbeat
    $stay();
    $svc->tickMembers();

    expect($m->fresh()->status)->toBe('left')->and(attLog($u))->toBe(['in', 'auto_out']);
});

// ───── nobody checked in (spec §5) ─────

it('keeps a customer in the lounge with the no-estimate message while nobody is in, and gives her to the first who checks in', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:05', 'Africa/Cairo'));
    app(ShiftService::class)->transition();
    $c = Conversation::factory()->create(['last_customer_message_at' => now()]);

    $e = app(QueueService::class)->enqueue($c, new HandoverContext('human_request', 'human_request', 'medium', null, [], 'unknown'));

    expect($e->status)->toBe('waiting')->and($e->priority)->toBe('live')->and($e->eta_seconds)->toBeNull()
        ->and($c->messages()->where('sender_type', 'bot')->latest('id')->value('body'))
        ->toContain('رقم تذكرتك #'.$e->ticket_no)->toContain('الفريق بيبدأ دلوقتي');

    $u = attModerator();
    app(ShiftService::class)->checkIn($u);

    expect($e->fresh()->status)->toBe('active')->and($e->fresh()->assigned_user_id)->toBe($u->id);
});

// ───── on her behalf ─────

it('records who sent her on a break, brought her back or checked her out on her behalf', function () {
    $shift = Shift::factory()->create();
    $leader = User::query()->findOrFail($shift->leader_user_id);
    $u = attModerator();
    $svc = app(ShiftService::class);
    $m = $svc->checkIn($u);

    $svc->setStatus($m, 'break', $leader);
    $svc->setStatus($m, 'available', $leader);
    expect($svc->checkOut($m->fresh(), $leader))->toBe('left');

    expect(attLog($u))->toBe(['in', 'break:'.$leader->id, 'back:'.$leader->id, 'out:'.$leader->id]);
});

it('credits the hand-back to whoever pressed it, not to whoever pressed «خروج»', function () {
    $shift = Shift::factory()->create();
    $leader = User::query()->findOrFail($shift->leader_user_id);
    $svc = app(ShiftService::class);
    $u = attModerator();
    $m = $svc->checkIn($u);
    attWindow($m);

    expect($svc->checkOut($m))->toBe('checking_out');
    expect($svc->handBack($m->fresh(), $leader))->toBe(1)
        ->and($m->fresh()->status)->toBe('left')
        ->and(attLog($u))->toBe(['in', 'out:'.$leader->id]);

    // The other way round: the leader pressed «خروج», she pressed hand-back herself.
    $v = attModerator();
    $n = $svc->checkIn($v);
    attWindow($n);
    $svc->checkOut($n, $leader);
    $svc->handBack($n->fresh(), $v);

    expect(attLog($v))->toBe(['in', 'out']);
});

it('at the clock close checks out every desk on the shift, frees their overnight reservations and tells each of them', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 17:50', 'Africa/Cairo'));
    $svc = app(ShiftService::class);
    $svc->transition();
    $a = attModerator();
    $b = attModerator();
    $ma = $svc->checkIn($a);
    $mb = $svc->checkIn($b);
    $svc->setStatus($mb, 'break', $b);
    $reserved = QueueEntry::factory()->create(['status' => 'waiting', 'priority' => 'overnight', 'reserved_user_id' => $a->id]);

    Event::fake([QueueMemberUpdated::class]);
    Carbon::setTestNow(Carbon::parse('2026-10-05 18:00:30', 'Africa/Cairo'));
    $svc->transition();

    expect($ma->fresh()->status)->toBe('left')->and($mb->fresh()->status)->toBe('left')
        ->and($ma->fresh()->left_at)->not->toBeNull()
        ->and(attLog($a))->toBe(['in', 'auto_out'])->and(attLog($b))->toBe(['in', 'break', 'auto_out'])
        ->and($reserved->fresh()->reserved_user_id)->toBeNull()
        ->and(Shift::where('shift_key', 'morning')->first()->status)->toBe('closed');

    foreach ([$ma, $mb] as $desk) {
        Event::assertDispatched(
            QueueMemberUpdated::class,
            fn ($ev) => $ev->member->id === $desk->id && $ev->member->status === 'left'
                && collect($ev->broadcastOn())->contains(fn ($ch) => $ch->name === 'private-user.'.$desk->user_id),
        );
    }
});
