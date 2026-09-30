<?php

use App\Models\Conversation;
use App\Models\QueueAttendanceEvent;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Models\UserNotification;
use App\Queue\Attendance;
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

// ───── attendance §4: the day's figures ─────

it('adds up her day: first in, last out, time worked, breaks and overruns', function () {
    QueueSetting::current()->update(['break_minutes' => 30]);
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Africa/Cairo'));
    app(ShiftService::class)->transition();
    $u = attModerator();
    $svc = app(ShiftService::class);
    $at = function (string $time) use ($u) {
        Carbon::setTestNow(Carbon::parse('2026-10-05 '.$time, 'Africa/Cairo'));
        $u->forceFill(['last_seen_at' => now()])->save();
    };

    $at('10:02');
    $m = $svc->checkIn($u);
    $at('12:00');
    $svc->setStatus($m, 'break', $u);
    $at('12:20');
    $svc->setStatus($m, 'available', $u); // 20 minutes
    $at('15:00');
    $svc->setStatus($m, 'break', $u);
    $at('15:40');
    $svc->setStatus($m, 'available', $u); // 40 minutes: past the 30
    $at('16:00');
    $svc->checkOut($m->fresh());
    $at('16:30');
    $m = $svc->checkIn($u); // back the same shift
    $at('17:00');

    $f = app(Attendance::class)->figuresFor([$u->id], '2026-10-05', 30)[$u->id];

    expect(Carbon::parse($f['first_in'])->equalTo(Carbon::parse('2026-10-05 10:02', 'Africa/Cairo')))->toBeTrue()
        ->and($f['last_out'])->toBeNull()->and($f['checked_in'])->toBeTrue()
        ->and($f['break_count'])->toBe(2)->and($f['break_seconds'])->toBe(60 * 60)->and($f['overruns'])->toBe(1)
        // (10:02 → 16:00) + (16:30 → 17:00) = 358 + 30 minutes, less the 60 minutes of breaks
        ->and($f['worked_seconds'])->toBe((358 + 30 - 60) * 60);

    $at('17:10');
    $svc->checkOut($m);
    $f = app(Attendance::class)->figuresFor([$u->id], '2026-10-05', 30)[$u->id];

    expect(Carbon::parse($f['last_out'])->equalTo(Carbon::parse('2026-10-05 17:10', 'Africa/Cairo')))->toBeTrue()
        ->and($f['checked_in'])->toBeFalse()
        ->and($f['worked_seconds'])->toBe((358 + 40 - 60) * 60)
        ->and(app(Attendance::class)->figuresFor([999999], '2026-10-05', 30)[999999])->toBe(Attendance::EMPTY);
});

it('counts a break still running up to now, and as an overrun once it is past the limit', function () {
    Shift::factory()->create();
    $u = attModerator();
    $svc = app(ShiftService::class);
    $m = $svc->checkIn($u); // 12:00
    Carbon::setTestNow(now()->addHour());
    $svc->setStatus($m, 'break', $u); // 13:00
    Carbon::setTestNow(now()->addMinutes(35)); // 13:35, still on it

    $f = app(Attendance::class)->figuresFor([$u->id], '2026-10-05', 30)[$u->id];

    expect($f['checked_in'])->toBeTrue()->and($f['break_count'])->toBe(1)->and($f['break_seconds'])->toBe(35 * 60)
        ->and($f['overruns'])->toBe(1)->and($f['worked_seconds'])->toBe(60 * 60);
});

/** The figures of one day from [event, H:i] steps, as the log would return them. */
function attCompute(array $steps, string $now, int $limit = 30): array
{
    $events = array_map(fn (array $s) => new QueueAttendanceEvent([
        // In the app timezone, as the column is stored and read back.
        'event' => $s[0], 'at' => Carbon::parse('2026-10-05 '.$s[1], 'Africa/Cairo')->setTimezone(config('app.timezone')),
    ]), $steps);

    return Attendance::compute($events, $limit, Carbon::parse('2026-10-05 '.$now, 'Africa/Cairo'));
}

it('closes an open break at the check-out, which writes no back', function () {
    $f = attCompute([['in', '10:00'], ['break', '12:00'], ['out', '12:45']], '18:00');

    expect($f['break_seconds'])->toBe(45 * 60)->and($f['break_count'])->toBe(1)->and($f['overruns'])->toBe(1)
        ->and($f['worked_seconds'])->toBe(120 * 60)->and($f['checked_in'])->toBeFalse()->and($f['last_out'])->not->toBeNull();
});

it('closes an open break at the automatic check-out, and at a new check-in', function () {
    $auto = attCompute([['in', '10:00'], ['break', '11:00'], ['auto_out', '11:10']], '18:00');
    expect($auto['break_seconds'])->toBe(10 * 60)->and($auto['overruns'])->toBe(0)->and($auto['worked_seconds'])->toBe(60 * 60);

    // Two in rows in a row (the out never written): the break ends at the second, the stay is not counted twice.
    $again = attCompute([['in', '10:00'], ['break', '11:00'], ['in', '11:20']], '12:00');
    expect($again['break_seconds'])->toBe(20 * 60)->and($again['worked_seconds'])->toBe((120 - 20) * 60);
});

it('adds a double shift and a re-check-in as separate stays', function () {
    $f = attCompute([['in', '09:00'], ['out', '11:00'], ['in', '11:30'], ['break', '12:00'], ['back', '12:10'], ['auto_out', '14:00'], ['in', '18:00'], ['out', '20:00']], '23:00');

    expect($f['worked_seconds'])->toBe((120 + 150 - 10 + 120) * 60)->and($f['break_seconds'])->toBe(10 * 60)->and($f['break_count'])->toBe(1)
        ->and($f['overruns'])->toBe(0)
        ->and(Carbon::parse($f['first_in'])->equalTo(Carbon::parse('2026-10-05 09:00', 'Africa/Cairo')))->toBeTrue()
        ->and(Carbon::parse($f['last_out'])->equalTo(Carbon::parse('2026-10-05 20:00', 'Africa/Cairo')))->toBeTrue();
});

it('counts a running day up to now, an exact-limit break is not an overrun, and an empty day is the empty figures', function () {
    $f = attCompute([['in', '10:00'], ['break', '11:00'], ['back', '11:30']], '13:00');
    expect($f['worked_seconds'])->toBe(150 * 60)->and($f['overruns'])->toBe(0)->and($f['checked_in'])->toBeTrue()->and($f['last_out'])->toBeNull();

    expect(attCompute([], '13:00'))->toBe(Attendance::EMPTY);
});

// ───── final review: template hours on the day, «رجعت» while closing, the tick and a break ─────

it('moves today\'s planned shift when its hours are edited on the day: moved earlier, it opens at the new time on «بدأت شغل»', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:00', 'Africa/Cairo'));
    app(ShiftService::class)->transition(); // the tick makes today's rows early, with the hours of that moment
    $morning = Shift::query()->where('shift_key', 'morning')->firstOrFail();
    expect($morning->status)->toBe('planned');

    Carbon::setTestNow(Carbon::parse('2026-10-05 09:15', 'Africa/Cairo'));
    $templates = QueueSetting::DEFAULT_SHIFTS;
    $templates[0]['from'] = '09:00';
    $templates[0]['name'] = 'صباحي بدري';
    $this->actingAs(User::factory()->create(['role' => 'admin']))->put('/settings/queue', ['shifts' => $templates])
        ->assertRedirect()->assertSessionHasNoErrors();

    $morning->refresh();
    expect($morning->starts_at->equalTo(Carbon::parse('2026-10-05 09:00', 'Africa/Cairo')))->toBeTrue()
        ->and($morning->ends_at->equalTo(Carbon::parse('2026-10-05 18:00', 'Africa/Cairo')))->toBeTrue()
        ->and($morning->name)->toBe('صباحي بدري')->and($morning->status)->toBe('planned');

    $u = attModerator();
    $this->actingAs($u)->getJson('/queue/me')->assertOk()->assertJsonPath('data.attendance.shift_open', true);
    $this->actingAs($u)->postJson('/queue/me/check-in')->assertOk()->assertJsonPath('data.member.status', 'available');

    expect($morning->fresh()->status)->toBe('open')->and(attLog($u))->toBe(['in']);
});

it('moves the end of the open shift when its hours are edited, not its start, and the next tick closes it when the new end has passed', function () {
    app(ShiftService::class)->transition(); // 12:00: the morning opens
    $morning = Shift::query()->where('shift_key', 'morning')->firstOrFail();
    $u = attModerator();
    $m = app(ShiftService::class)->checkIn($u);

    $templates = QueueSetting::DEFAULT_SHIFTS;
    $templates[0]['from'] = '09:00';
    $templates[0]['to'] = '11:30';
    $this->actingAs(User::factory()->create(['role' => 'admin']))->put('/settings/queue', ['shifts' => $templates])
        ->assertRedirect()->assertSessionHasNoErrors();

    $morning->refresh();
    expect($morning->status)->toBe('open')
        ->and($morning->starts_at->equalTo(Carbon::parse('2026-10-05 10:00', 'Africa/Cairo')))->toBeTrue()
        ->and($morning->ends_at->equalTo(Carbon::parse('2026-10-05 11:30', 'Africa/Cairo')))->toBeTrue();

    app(ShiftService::class)->transition();

    expect($morning->fresh()->status)->toBe('closed')->and($m->fresh()->status)->toBe('left')->and(attLog($u))->toBe(['in', 'auto_out']);
});

it('cancels her check-out on «رجعت»: back at her desk with her windows, no attendance event, and her last window no longer checks her out', function () {
    Shift::factory()->create();
    $svc = app(ShiftService::class);
    $u = attModerator();
    $m = $svc->checkIn($u);
    $e = attWindow($m);

    $this->actingAs($u)->postJson('/queue/me/check-out')->assertOk()->assertJsonPath('data.member.status', 'checking_out');
    $this->actingAs($u)->postJson('/queue/me/status', ['status' => 'available'])->assertOk()->assertJsonPath('data.status', 'busy');

    expect($m->fresh()->status)->toBe('busy')->and($m->fresh()->requested_by_id)->toBeNull()->and(attLog($u))->toBe(['in']);

    app(WindowLifecycle::class)->close($e, 'inquiry', $u);

    expect($m->fresh()->status)->not->toBe('left')->and(attLog($u))->toBe(['in']);
});

it('lets the leader cancel a check-out from the board, and a desk checking out without windows comes back available', function () {
    $shift = Shift::factory()->create();
    $leader = User::query()->findOrFail($shift->leader_user_id);
    $svc = app(ShiftService::class);
    $u = attModerator();
    $m = $svc->checkIn($u);
    attWindow($m);
    $svc->checkOut($m, $leader);
    expect($m->fresh()->requested_by_id)->toBe($leader->id);

    $this->actingAs($leader)->postJson("/board/members/{$m->id}/status", ['status' => 'available'])->assertOk()
        ->assertJsonPath('data.members.0.status', 'busy');
    expect($m->fresh()->requested_by_id)->toBeNull()->and(attLog($u))->toBe(['in']);

    // A break is still not taken over a check-out: only «رجعت» changes it.
    $v = attModerator();
    $n = $svc->checkIn($v);
    $n->update(['status' => 'checking_out']); // her last window closed a moment ago; settle() has not run yet
    $svc->setStatus($n, 'break', $v);
    expect($n->fresh()->status)->toBe('checking_out');

    $svc->setStatus($n, 'available', $v);
    expect($n->fresh()->status)->toBe('available')->and(attLog($v))->toBe(['in']);
});

it('starts the pending break when the tick hands her windows on, and does not turn it into offline', function () {
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
    $svc->setStatus($m, 'break', $u);
    expect($m->fresh()->status)->toBe('pending_break');

    // Four minutes quiet: offline, but the pending break stays (her window is not handed on yet).
    Carbon::setTestNow(now()->addMinutes(4));
    $stay();
    $svc->tickMembers();
    expect($m->fresh()->status)->toBe('pending_break')->and($e->fresh()->status)->toBe('active');

    // Six minutes: her window goes on, the break starts, and it stays a break.
    Carbon::setTestNow(now()->addMinutes(2));
    $stay();
    $svc->tickMembers();
    expect($e->fresh()->close_reason)->toBe('transfer')->and($m->fresh()->status)->toBe('break')->and(attLog($u))->toBe(['in', 'break']);

    Carbon::setTestNow(now()->addMinutes(1));
    $stay();
    $svc->tickMembers();
    expect($m->fresh()->status)->toBe('break')->and(attLog($u))->toBe(['in', 'break']);
});

it('never marks a desk on a break or a pending break offline', function () {
    $shift = Shift::factory()->create();
    $svc = app(ShiftService::class);
    $a = tap(ShiftMember::factory()->for($shift)->create(['user_id' => attModerator()->id, 'status' => 'break']))->load('user');
    $b = tap(ShiftMember::factory()->for($shift)->create(['user_id' => attModerator()->id, 'status' => 'pending_break']))->load('user');

    $svc->setStatus($a, 'offline');
    $svc->setStatus($b, 'offline');

    expect($a->fresh()->status)->toBe('break')->and($b->fresh()->status)->toBe('pending_break');
});
