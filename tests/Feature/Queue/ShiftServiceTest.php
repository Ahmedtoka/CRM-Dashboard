<?php

use App\Http\Resources\ShiftMemberResource;
use App\Models\ActivityLog;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Models\UserNotification;
use App\Queue\Events\QueueMemberUpdated;
use App\Queue\ShiftService;
use App\Queue\WindowLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

beforeEach(fn () => QueueSetting::factory()->create(['id' => 1, 'enabled' => true]));

it('puts a busy member on pending break, then on break when her windows close, and she stays on it until she comes back', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 13:00', 'Africa/Cairo'));
    $shift = Shift::factory()->create();
    $m = ShiftMember::factory()->for($shift)->create();
    $m->user->forceFill(['last_seen_at' => now()])->save();
    QueueEntry::factory()->create(['shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active', 'delivered_at' => now()]);
    $svc = app(ShiftService::class);

    $svc->setStatus($m, 'break');
    expect($m->fresh()->status)->toBe('pending_break');
    QueueEntry::query()->update(['status' => 'closed']);
    $svc->tickMembers();
    expect($m->fresh()->status)->toBe('break');

    Carbon::setTestNow(now()->addMinutes(31)); // no automatic return (attendance §3)
    $svc->tickMembers();
    expect($m->fresh()->status)->toBe('break');

    $svc->setStatus($m, 'available');
    expect($m->fresh()->status)->toBe('available');
});

it('marks a member offline after 3 minutes without heartbeat and re-queues her windows after 5', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $shift = Shift::factory()->create(['opened_at' => now()->subHour()]);
    $m = ShiftMember::factory()->for($shift)->create(['status' => 'busy', 'joined_at' => now()->subHour()]);
    $m->user->forceFill(['last_seen_at' => now()->subMinutes(4)])->save();
    $e = QueueEntry::factory()->create(['shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active', 'delivered_at' => now()->subMinutes(4)]);
    $e->conversation->update(['assignee_id' => $m->user_id, 'queue_entry_id' => $e->id]);
    app(ShiftService::class)->tickMembers();
    expect($m->fresh()->status)->toBe('offline')->and($e->fresh()->status)->toBe('active');
    $m->user->forceFill(['last_seen_at' => now()->subMinutes(6)])->save();
    app(ShiftService::class)->tickMembers();
    $fresh = QueueEntry::where('conversation_id', $e->conversation_id)->latest('id')->first();
    expect($e->fresh()->status)->toBe('closed')->and($e->fresh()->close_reason)->toBe('transfer')->and($fresh->priority)->toBe('returning')->and($fresh->status)->toBe('waiting');
});

it('describes a member desk with her open windows and today counters', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $m = ShiftMember::factory()->for(Shift::factory())->create(['status' => 'busy']);
    QueueEntry::factory()->create(['shift_member_id' => $m->id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now()->subSeconds(130), 'last_customer_message_at' => now()->subSeconds(120), 'last_agent_message_at' => now()->subSeconds(100)]); // the clock runs from her last reply
    QueueEntry::factory()->create(['shift_member_id' => $m->id, 'status' => 'closed', 'close_reason' => 'inquiry']);
    QueueEntry::factory()->create(['shift_member_id' => $m->id, 'status' => 'closed', 'close_reason' => 'auto']);
    $data = (new ShiftMemberResource($m))->resolve();
    expect($data['open_count'])->toBe(1)->and($data['windows'][0]['window_no'])->toBe(1)
        ->and($data['windows'][0]['silence_left_seconds'])->toBe(200)
        ->and($data['today'])->toBe(['received' => 3, 'inquiry' => 1, 'problem' => 0, 'case' => 0, 'auto' => 1, 'escalation' => 0, 'no_reply' => 0])
        ->and($data['user']['id'])->toBe($m->user_id);
});

it('transfers the same customer twice in one day without a ticket collision', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $shift = Shift::factory()->create();
    $m = ShiftMember::factory()->for($shift)->create(['status' => 'busy']);
    $first = QueueEntry::factory()->create(['ticket_no' => 5, 'shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active', 'delivered_at' => now()]);
    $lifecycle = app(WindowLifecycle::class);
    $second = $lifecycle->transferAway($first, 'offline');
    $second->update(['status' => 'active', 'shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'delivered_at' => now()]);
    $third = $lifecycle->transferAway($second, 'offline');
    expect($first->fresh()->ticket_no)->toBe(100005)->and($second->fresh()->ticket_no)->toBe(200005)
        ->and($third->ticket_no)->toBe(5)->and($third->status)->toBe('waiting')
        ->and($first->fresh()->close_reason)->toBe('transfer')->and($second->fresh()->close_reason)->toBe('transfer')
        ->and($third->conversation->queue_entry_id)->toBe($third->id);
});

it('checks a member deactivated mid-shift out right away', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $m = ShiftMember::factory()->for(Shift::factory())->create(['status' => 'available']);
    $m->user->forceFill(['last_seen_at' => now(), 'is_active' => false])->save();

    app(ShiftService::class)->tickMembers();

    expect($m->fresh()->status)->toBe('left');
});

// ───── review I9: status changes under the member lock ─────

it('puts her on pending break when she holds a window on another shift row (counted per user)', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 18:05', 'Africa/Cairo'));
    $morning = Shift::factory()->create(['status' => 'closed']);
    $evening = Shift::factory()->create(['shift_key' => 'evening']);
    $u = User::factory()->create(['last_seen_at' => now()]);
    $old = ShiftMember::factory()->for($morning)->create(['user_id' => $u->id, 'status' => 'left']);
    $m = ShiftMember::factory()->for($evening)->create(['user_id' => $u->id, 'status' => 'busy']);
    QueueEntry::factory()->create(['shift_member_id' => $old->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1]);

    app(ShiftService::class)->setStatus($m, 'break');

    expect($m->fresh()->status)->toBe('pending_break')->and($m->fresh()->break_started_at)->toBeNull();
});

it('keeps a break at its fixed length: asking again does not restart it', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 13:00', 'Africa/Cairo'));
    QueueSetting::current()->update(['break_minutes' => 30]);
    $m = ShiftMember::factory()->for(Shift::factory())->create(['status' => 'available']);
    $svc = app(ShiftService::class);

    $svc->setStatus($m, 'break');
    $ends = $m->fresh()->break_ends_at;
    Carbon::setTestNow(now()->addMinutes(20));
    $svc->setStatus($m, 'break');

    expect($m->fresh()->status)->toBe('break')->and($m->fresh()->break_ends_at->equalTo($ends))->toBeTrue()
        ->and($ends->equalTo(Carbon::parse('2026-10-05 13:30', 'Africa/Cairo')))->toBeTrue();
});

it('decides a break on the locked row, not on the copy the caller holds', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 13:00', 'Africa/Cairo'));
    $shift = Shift::factory()->create();
    $m = ShiftMember::factory()->for($shift)->create(['status' => 'available']);
    $stale = ShiftMember::query()->find($m->id); // read before the assignment below
    // An assignment committed after her copy was read (the router locks the member row last).
    QueueEntry::factory()->create(['shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active', 'window_no' => 1]);
    ShiftMember::query()->whereKey($m->id)->update(['status' => 'busy']);

    app(ShiftService::class)->setStatus($stale, 'break');

    expect($m->fresh()->status)->toBe('pending_break')->and($stale->status)->toBe('pending_break');
});

it('leaves a member who left the shift as she is', function () {
    $m = ShiftMember::factory()->for(Shift::factory())->create(['status' => 'left']);

    app(ShiftService::class)->setStatus($m, 'available');

    expect($m->fresh()->status)->toBe('left');
});

// ───── smoke test: mass offline ─────

/** A serving desk on a shift opened an hour ago, that has worked since then, and whose heartbeat stopped `$silentFor` seconds ago, with one open window. */
function darkDesk(Shift $shift, int $silentFor): array
{
    $shift->update(['opened_at' => now()->subHour()]);
    $u = User::factory()->create(['last_seen_at' => now()->subSeconds($silentFor)]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy', 'break_at' => now()->addHours(3), 'joined_at' => now()->subHour()]);
    $e = QueueEntry::factory()->create(['shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now()->subMinutes(10)]);
    $e->conversation->update(['assignee_id' => $u->id, 'queue_entry_id' => $e->id]);

    return [$m, $e];
}

it('marks nobody offline and hands off no window when every serving desk goes quiet in the same tick', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    Log::spy();
    $shift = Shift::factory()->create();
    [$a, $ea] = darkDesk($shift, 400);
    [$b, $eb] = darkDesk($shift, 400);
    $supervisor = User::factory()->create(['role' => 'supervisor']);
    $admin = User::factory()->create(['role' => 'admin']);

    app(ShiftService::class)->tickMembers();
    Carbon::setTestNow(now()->addSeconds(30));
    app(ShiftService::class)->tickMembers();

    expect($a->fresh()->status)->toBe('busy')->and($b->fresh()->status)->toBe('busy')
        ->and($ea->fresh()->status)->toBe('active')->and($eb->fresh()->status)->toBe('active')
        ->and(QueueEntry::where('status', 'waiting')->count())->toBe(0)
        // Once per 15 minutes, to every supervisor and admin.
        ->and(UserNotification::where('type', 'queue.mass_offline')->where('user_id', $supervisor->id)->count())->toBe(1)
        ->and(UserNotification::where('type', 'queue.mass_offline')->where('user_id', $admin->id)->count())->toBe(1)
        ->and(UserNotification::where('type', 'queue.mass_offline')->first()->data)->toMatchArray(['count' => 2, 'serving' => 2]);
    Log::shouldHaveReceived('warning')->with('queue.mass_offline', ['dark' => 2, 'serving' => 2])->twice();

    Carbon::setTestNow(now()->addMinutes(15));
    app(ShiftService::class)->tickMembers();
    expect(UserNotification::where('type', 'queue.mass_offline')->where('user_id', $supervisor->id)->count())->toBe(2);
});

it('holds back when more than half of at least three serving desks go quiet at once', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    Log::spy();
    $shift = Shift::factory()->create();
    [$a, $ea] = darkDesk($shift, 200);
    [$b] = darkDesk($shift, 200);
    [$c] = darkDesk($shift, 5);

    app(ShiftService::class)->tickMembers();

    expect($a->fresh()->status)->toBe('busy')->and($b->fresh()->status)->toBe('busy')->and($c->fresh()->status)->toBe('busy')
        ->and($ea->fresh()->status)->toBe('active');
    Log::shouldHaveReceived('warning')->with('queue.mass_offline', ['dark' => 2, 'serving' => 3])->once();
});

it('still takes one quiet moderator offline and hands her windows on when the others are fine', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $shift = Shift::factory()->create();
    [$a, $ea] = darkDesk($shift, 400);
    [$b] = darkDesk($shift, 5);
    [$c] = darkDesk($shift, 5);

    app(ShiftService::class)->tickMembers();

    expect($a->fresh()->status)->toBe('offline')->and($b->fresh()->status)->toBe('busy')->and($c->fresh()->status)->toBe('busy')
        ->and($ea->fresh()->status)->toBe('closed')->and($ea->fresh()->close_reason)->toBe('transfer')
        ->and(UserNotification::where('type', 'queue.mass_offline')->count())->toBe(0);
});

// ───── flow revision §2: not arrived vs went dark ─────

it('checks out desks never seen since they joined at once, without the mass-offline hold', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $shift = Shift::factory()->create(['opened_at' => now()->subMinutes(2)]);
    $a = ShiftMember::factory()->for($shift)->create(['status' => 'available', 'joined_at' => now()->subMinutes(2)]);
    $b = ShiftMember::factory()->for($shift)->create(['status' => 'available', 'joined_at' => now()->subMinutes(2)]);

    app(ShiftService::class)->tickMembers();

    expect($a->fresh()->status)->toBe('left')->and($b->fresh()->status)->toBe('left')
        ->and(UserNotification::where('type', 'queue.mass_offline')->count())->toBe(0);
});

it('counts a moderator last seen before she joined as not arrived, and one online when she was added as arrived', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 18:05', 'Africa/Cairo'));
    $shift = Shift::factory()->create(['shift_key' => 'evening', 'opened_at' => now()->subMinutes(5)]);
    $yesterday = User::factory()->create(['last_seen_at' => now()->subHours(20)]);
    $justNow = User::factory()->create(['last_seen_at' => now()->subSeconds(50)]);
    $away = ShiftMember::factory()->for($shift)->create(['user_id' => $yesterday->id, 'joined_at' => now()->subMinutes(5)]);
    $here = ShiftMember::factory()->for($shift)->create(['user_id' => $justNow->id, 'joined_at' => now()->subSeconds(30)]);

    expect(ShiftService::notArrived($away->load('user')))->toBeTrue()
        ->and(ShiftService::notArrived($here->load('user')))->toBeFalse();
});

it('still holds back working desks that go dark together while a desk that never arrived is checked out', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $shift = Shift::factory()->create();
    [$a, $ea] = darkDesk($shift, 400);
    [$b] = darkDesk($shift, 400);
    $never = ShiftMember::factory()->for($shift)->create(['status' => 'available', 'joined_at' => now()->subHour()]);

    app(ShiftService::class)->tickMembers();

    expect($a->fresh()->status)->toBe('busy')->and($b->fresh()->status)->toBe('busy')->and($ea->fresh()->status)->toBe('active')
        ->and($never->fresh()->status)->toBe('left')
        ->and(UserNotification::where('type', 'queue.mass_offline')->first()->data)->toMatchArray(['count' => 2, 'serving' => 2]);
});

it('says on the desk whether its moderator is logged in, in the resource and in the broadcast', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $shift = Shift::factory()->create();
    $on = ShiftMember::factory()->for($shift)->create(['user_id' => User::factory()->create(['last_seen_at' => now()])->id]);
    $off = ShiftMember::factory()->for($shift)->create(['user_id' => User::factory()->create(['last_seen_at' => now()->subMinutes(3)])->id]);

    expect((new ShiftMemberResource($on))->resolve()['online'])->toBeTrue()
        ->and((new ShiftMemberResource($off))->resolve()['online'])->toBeFalse()
        ->and((new QueueMemberUpdated($off->fresh()))->broadcastWith()['online'])->toBeFalse();
});

// ───── attendance design §2: shifts run by the clock ─────

it('opens and closes the shifts by the clock with nobody on them, whoever was remembered', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:59', 'Africa/Cairo'));
    $leader = User::factory()->create(['role' => 'supervisor']);
    $someone = User::factory()->create();
    $templates = QueueSetting::DEFAULT_SHIFTS;
    $templates[0]['leader_user_id'] = $leader->id;
    $templates[1]['leader_user_id'] = $leader->id;
    QueueSetting::current()->update(['shifts' => $templates, 'default_roster' => ['morning' => [$someone->id], 'evening' => [$someone->id]]]);
    $svc = app(ShiftService::class);

    $svc->transition();
    expect(Shift::where('status', 'open')->count())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Africa/Cairo'));
    $svc->transition();
    $morning = Shift::where('shift_key', 'morning')->first();
    expect($morning->status)->toBe('open')->and($morning->leader_user_id)->toBe($leader->id)->and($morning->opened_by_id)->toBeNull()
        ->and(ShiftMember::count())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-10-05 18:00', 'Africa/Cairo'));
    $svc->transition();
    expect($morning->fresh()->status)->toBe('closed')
        ->and(Shift::where('shift_key', 'evening')->first()->status)->toBe('open')
        ->and(ShiftMember::count())->toBe(0);
});

it('closes the evening shift after midnight even though the business date moved on', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 18:30', 'Africa/Cairo'));
    app(ShiftService::class)->transition();
    $shift = Shift::where('status', 'open')->first();
    expect($shift->shift_key)->toBe('evening')
        ->and($shift->ends_at->setTimezone('Africa/Cairo')->format('Y-m-d H:i'))->toBe('2026-10-06 00:00');

    Carbon::setTestNow(Carbon::parse('2026-10-06 00:01', 'Africa/Cairo'));
    app(ShiftService::class)->transition();

    expect($shift->fresh()->status)->toBe('closed')->and(Shift::where('status', 'open')->count())->toBe(0);
});

it('opens a shift once when two callers race for it', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Africa/Cairo'));
    $svc = app(ShiftService::class);
    $planned = $svc->todayShifts()->firstWhere('shift_key', 'morning');
    $stale = Shift::query()->find($planned->id); // read as planned by the second caller
    $svc->transition();
    $openedAt = $planned->fresh()->opened_at;

    Carbon::setTestNow(now()->addSeconds(20));
    (fn () => $this->open($stale))->call($svc);

    expect($planned->fresh()->opened_at->equalTo($openedAt))->toBeTrue()
        ->and(ActivityLog::query()->where('action', 'shift.open')->count())->toBe(1);
});

it('no longer tells anybody that a moderator has not arrived', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:30', 'Africa/Cairo'));
    User::factory()->create(['role' => 'supervisor']);
    $shift = Shift::factory()->create(['opened_at' => now()->subMinutes(30)]);
    ShiftMember::factory()->for($shift)->create(['joined_at' => now()->subMinutes(30)]);

    app(ShiftService::class)->tickMembers();

    expect(UserNotification::where('type', 'queue.member_not_arrived')->count())->toBe(0);
});

it('opens a shift with the leader its template names when it opens, not when its row was made', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 09:00', 'Africa/Cairo'));
    $svc = app(ShiftService::class);
    $svc->todayShifts(); // the tick makes today's rows early, with the templates' leaders of that moment (nobody)
    $leader = User::factory()->create(['role' => 'supervisor']);
    $templates = QueueSetting::DEFAULT_SHIFTS;
    $templates[0]['leader_user_id'] = $leader->id;
    QueueSetting::current()->update(['shifts' => $templates]);
    QueueEntry::factory()->create(['priority' => 'escalation']);

    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Africa/Cairo'));
    $svc->transition();

    expect(Shift::where('shift_key', 'morning')->first()->leader_user_id)->toBe($leader->id)
        ->and(QueueEntry::where('priority', 'escalation')->first()->reserved_user_id)->toBe($leader->id);
});
