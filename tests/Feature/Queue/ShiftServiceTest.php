<?php

use App\Http\Resources\ShiftMemberResource;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Models\UserNotification;
use App\Queue\Data\HandoverContext;
use App\Queue\Events\QueueMemberUpdated;
use App\Queue\QueueService;
use App\Queue\ShiftService;
use App\Queue\WindowLifecycle;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

beforeEach(fn () => QueueSetting::factory()->create(['id' => 1, 'enabled' => true]));

it('starts the day from templates, splits the overnight backlog evenly and schedules breaks', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 03:00', 'Africa/Cairo'));
    $svc = app(QueueService::class);
    // The conversation factory takes its platform from the channel account, so the account is Facebook.
    $fb = ChannelAccount::factory()->create(['platform' => 'facebook']);
    for ($i = 0; $i < 4; $i++) {
        $svc->enqueue(Conversation::factory()->for($fb)->create(['platform' => 'facebook']), new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));
    }
    expect(QueueEntry::where('priority', 'overnight')->count())->toBe(4);
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Africa/Cairo'));
    $leader = User::factory()->create(['role' => 'supervisor']);
    [$a, $b] = User::factory()->count(2)->create()->each(fn ($u) => $u->userPlatforms()->create(['platform' => 'facebook']))->all();
    $shift = app(ShiftService::class)->startDay(['morning' => [$a->id, $b->id], 'evening' => []], $leader);
    expect($shift->status)->toBe('open')->and($shift->members)->toHaveCount(2)
        ->and(QueueEntry::where('reserved_user_id', $a->id)->count())->toBe(2)->and(QueueEntry::where('reserved_user_id', $b->id)->count())->toBe(2)
        ->and($shift->members->first()->break_at->setTimezone('Africa/Cairo')->format('H:i'))->toBe('13:00')
        ->and(QueueSetting::current()->default_roster['morning'])->toBe([$a->id, $b->id]);
});

it('closes the morning shift at 18:00 and opens the evening one with the default roster', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Africa/Cairo'));
    $leader = User::factory()->create(['role' => 'supervisor']);
    $m = User::factory()->create();
    $e = User::factory()->create();
    app(ShiftService::class)->startDay(['morning' => [$m->id], 'evening' => [$e->id]], $leader);
    Carbon::setTestNow(Carbon::parse('2026-10-05 18:01', 'Africa/Cairo'));
    app(ShiftService::class)->transition();
    expect(Shift::where('shift_key', 'morning')->first()->status)->toBe('closed')->and(Shift::where('shift_key', 'evening')->first()->status)->toBe('open')
        ->and(ShiftMember::where('user_id', $m->id)->first()->status)->toBe('left')->and(ShiftMember::where('user_id', $e->id)->first()->status)->toBe('available');
});

it('puts a busy member on pending break, then on break when her windows close, then back', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 13:00', 'Africa/Cairo'));
    $shift = Shift::factory()->create();
    $m = ShiftMember::factory()->for($shift)->create(['break_at' => now()]);
    // She is online (a never-seen member is offline immediately — ruling (d)).
    $m->user->forceFill(['last_seen_at' => now()])->save();
    QueueEntry::factory()->create(['shift_member_id' => $m->id, 'assigned_user_id' => $m->user_id, 'status' => 'active', 'delivered_at' => now()]);
    app(ShiftService::class)->tickMembers();
    expect($m->fresh()->status)->toBe('pending_break');
    QueueEntry::query()->update(['status' => 'closed']);
    app(ShiftService::class)->tickMembers();
    expect($m->fresh()->status)->toBe('break');
    Carbon::setTestNow(now()->addMinutes(31));
    app(ShiftService::class)->tickMembers();
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

it('closes the evening shift after midnight even though the business date moved on', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 18:30', 'Africa/Cairo'));
    $leader = User::factory()->create(['role' => 'supervisor']);
    $e = User::factory()->create();
    $shift = app(ShiftService::class)->startDay(['morning' => [], 'evening' => [$e->id]], $leader);
    expect($shift->shift_key)->toBe('evening')->and($shift->status)->toBe('open')
        ->and($shift->ends_at->setTimezone('Africa/Cairo')->format('Y-m-d H:i'))->toBe('2026-10-06 00:00');
    Carbon::setTestNow(Carbon::parse('2026-10-06 00:01', 'Africa/Cairo'));
    app(ShiftService::class)->transition();
    expect($shift->fresh()->status)->toBe('closed')->and(ShiftMember::where('user_id', $e->id)->first()->status)->toBe('left')
        ->and(Shift::where('status', 'open')->count())->toBe(0);
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
        ->and($data['today'])->toBe(['received' => 3, 'inquiry' => 1, 'problem' => 0, 'case' => 0, 'auto' => 1, 'escalation' => 0])
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

it('anchors breaks at the real start when the day is started late', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 14:00', 'Africa/Cairo'));
    $leader = User::factory()->create(['role' => 'supervisor']);
    [$a, $b] = User::factory()->count(2)->create()->all();
    $shift = app(ShiftService::class)->startDay(['morning' => [$a->id, $b->id], 'evening' => []], $leader);
    expect($shift->members->first()->break_at->setTimezone('Africa/Cairo')->format('H:i'))->toBe('17:00')
        ->and($shift->members->last()->break_at->setTimezone('Africa/Cairo')->format('H:i'))->toBe('17:20');
});

it('adds nothing when the roster names an unknown user', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Africa/Cairo'));
    $leader = User::factory()->create(['role' => 'supervisor']);
    $a = User::factory()->create();
    expect(fn () => app(ShiftService::class)->startDay(['morning' => [$a->id, 999999], 'evening' => []], $leader))
        ->toThrow(ModelNotFoundException::class);
    expect(ShiftMember::count())->toBe(0)->and(Shift::where('status', 'open')->count())->toBe(0)
        ->and(QueueSetting::current()->default_roster)->toBe([]);
});

it('treats a member deactivated mid-shift as offline right away', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $m = ShiftMember::factory()->for(Shift::factory())->create(['status' => 'available']);
    $m->user->forceFill(['last_seen_at' => now(), 'is_active' => false])->save();
    app(ShiftService::class)->tickMembers();
    expect($m->fresh()->status)->toBe('offline');
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

// ───── review I4 + leader desk at the start of the shift ─────

it('tells every moderator on her own channel when the shift she is on opens', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:05', 'Africa/Cairo'));
    Event::fake([QueueMemberUpdated::class]);
    $a = User::factory()->create(['role' => 'moderator']);
    $boss = User::factory()->create(['role' => 'supervisor']);

    app(ShiftService::class)->startDay(['morning' => [$a->id]], $boss);

    Event::assertDispatched(QueueMemberUpdated::class, fn (QueueMemberUpdated $ev) => $ev->member->user_id === $a->id
        && collect($ev->broadcastOn())->pluck('name')->contains('private-user.'.$a->id));
});

it('does not reserve overnight customers for the leader of the shift', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:05', 'Africa/Cairo'));
    $leader = User::factory()->create(['role' => 'supervisor']);
    $a = User::factory()->create(['role' => 'moderator']);
    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $p) {
        $a->userPlatforms()->create(['platform' => $p]);
    }
    QueueEntry::factory()->count(4)->create(['priority' => 'overnight']);

    app(ShiftService::class)->startDay(['morning' => [$a->id]], $leader, ['morning' => $leader->id]);

    expect(QueueEntry::where('priority', 'overnight')->pluck('reserved_user_id')->unique()->values()->all())->toBe([$a->id]);
});

// ───── flow revision §2: not arrived vs went dark ─────

it('marks rostered moderators who never logged in offline at once, without the mass-offline hold', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $shift = Shift::factory()->create(['opened_at' => now()->subMinutes(2)]);
    $a = ShiftMember::factory()->for($shift)->create(['status' => 'available', 'joined_at' => now()->subMinutes(2)]);
    $b = ShiftMember::factory()->for($shift)->create(['status' => 'available', 'joined_at' => now()->subMinutes(2)]);

    app(ShiftService::class)->tickMembers();

    expect($a->fresh()->status)->toBe('offline')->and($b->fresh()->status)->toBe('offline')
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

it('still holds back working desks that go dark together while a desk that never arrived goes offline', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $shift = Shift::factory()->create();
    [$a, $ea] = darkDesk($shift, 400);
    [$b] = darkDesk($shift, 400);
    $never = ShiftMember::factory()->for($shift)->create(['status' => 'available', 'joined_at' => now()->subHour()]);

    app(ShiftService::class)->tickMembers();

    expect($a->fresh()->status)->toBe('busy')->and($b->fresh()->status)->toBe('busy')->and($ea->fresh()->status)->toBe('active')
        ->and($never->fresh()->status)->toBe('offline')
        ->and(UserNotification::where('type', 'queue.mass_offline')->first()->data)->toMatchArray(['count' => 2, 'serving' => 2]);
});

it('tells the leader, supervisors and admins once when a rostered moderator has not logged in 10 minutes into the shift', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:05', 'Africa/Cairo'));
    $leader = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $supervisor = User::factory()->create(['role' => 'supervisor']);
    $admin = User::factory()->create(['role' => 'admin']);
    $shift = Shift::factory()->create(['leader_user_id' => $leader->id, 'opened_at' => now()->subMinutes(5)]);
    $late = ShiftMember::factory()->for($shift)->create(['joined_at' => now()->subMinutes(5)]);
    $svc = app(ShiftService::class);

    $svc->tickMembers();
    expect(UserNotification::where('type', 'queue.member_not_arrived')->count())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(5)); // ten minutes after the shift opened
    $svc->tickMembers();
    $svc->tickMembers();

    $sent = UserNotification::where('type', 'queue.member_not_arrived')->get();
    expect($sent->pluck('user_id')->sort()->values()->all())->toBe(collect([$leader->id, $supervisor->id, $admin->id])->sort()->values()->all())
        ->and($sent->first()->data)->toMatchArray(['member_id' => $late->id, 'user_id' => $late->user_id, 'minutes' => 10])
        ->and($late->fresh()->not_arrived_alerted_at)->not->toBeNull();
});

it('counts the ten minutes from when she was added when that is later than the shift opening', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    $shift = Shift::factory()->create(['opened_at' => now()->subHours(2)]);
    ShiftMember::factory()->for($shift)->create(['joined_at' => now()->subMinutes(3)]);

    app(ShiftService::class)->tickMembers();
    expect(UserNotification::where('type', 'queue.member_not_arrived')->count())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(7));
    app(ShiftService::class)->tickMembers();
    expect(UserNotification::where('type', 'queue.member_not_arrived')->where('user_id', $shift->leader_user_id)->count())->toBe(1);
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

it('counts an evening moderator rostered in the morning and seen at noon as not arrived when her shift opens', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 18:00', 'Africa/Cairo'));
    $leader = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $shift = Shift::factory()->create(['shift_key' => 'evening', 'leader_user_id' => $leader->id, 'opened_at' => now()]);
    $members = collect([1, 2])->map(fn () => ShiftMember::factory()->for($shift)->create([
        'status' => 'available', 'joined_at' => now()->subHours(9),
        'user_id' => User::factory()->create(['last_seen_at' => now()->subHours(5)])->id,
    ]));
    $svc = app(ShiftService::class);

    expect(ShiftService::notArrived($members[0]->fresh()->load('user')))->toBeTrue()
        ->and(ShiftService::notArrived($members[0]->fresh()))->toBeTrue();

    $svc->tickMembers();

    expect($members->map(fn ($m) => $m->fresh()->status)->all())->toBe(['offline', 'offline'])
        ->and(UserNotification::where('type', 'queue.mass_offline')->count())->toBe(0)
        ->and(UserNotification::where('type', 'queue.member_not_arrived')->count())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(10));
    $svc->tickMembers();

    expect(UserNotification::where('type', 'queue.member_not_arrived')->where('user_id', $leader->id)->count())->toBe(2);
});
