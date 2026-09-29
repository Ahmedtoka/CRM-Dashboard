<?php

use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\QueueDecision;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Models\UserNotification;
use App\Queue\Events\QueueAssigned;
use App\Queue\Events\QueueEntryUpdated;
use App\Queue\Events\RouterDecided;
use App\Queue\QueueRouter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true, 'windows_per_moderator' => 2]);
});

function routerMember(Shift $shift, array $platforms = ['facebook', 'instagram', 'whatsapp', 'tiktok'], array $attrs = []): ShiftMember
{
    $u = User::factory()->create(['last_seen_at' => now()]);
    foreach ($platforms as $p) {
        $u->userPlatforms()->create(['platform' => $p]);
    }

    return ShiftMember::factory()->for($shift)->create(['user_id' => $u->id] + $attrs);
}
function routerWaiting(array $attrs = []): QueueEntry
{
    static $n = 100;

    return QueueEntry::factory()->create(['ticket_no' => ++$n, 'enqueued_at' => now()->subSeconds(1000 - $n)] + $attrs);
}

it('routes live entries to the least loaded member and sets the window', function () {
    $shift = Shift::factory()->create();
    $a = routerMember($shift);
    $b = routerMember($shift);
    QueueEntry::factory()->create(['shift_member_id' => $a->id, 'assigned_user_id' => $a->user_id, 'status' => 'active', 'window_no' => 1]);
    $e = routerWaiting();
    expect(app(QueueRouter::class)->run('test'))->toBe(1);
    $e->refresh();
    expect($e->assigned_user_id)->toBe($b->user_id)->and($e->window_no)->toBe(1)->and($e->status)->toBe('active')->and($e->rule)->toContain('الأقل حملاً')
        ->and($e->conversation->fresh()->assignee_id)->toBe($b->user_id)
        ->and(UserNotification::where('user_id', $b->user_id)->where('type', 'queue.assigned')->exists())->toBeTrue();
});

it('returns a returning customer to the same member when she has a free window, else to anyone', function () {
    $shift = Shift::factory()->create();
    $a = routerMember($shift);
    $b = routerMember($shift);
    $e = routerWaiting(['priority' => 'returning', 'reserved_user_id' => $a->user_id]);
    app(QueueRouter::class)->run('t');
    expect($e->fresh()->assigned_user_id)->toBe($a->user_id)->and($e->fresh()->rule)->toContain('نفس الموظفة');
    QueueEntry::factory()->count(2)->create(['shift_member_id' => $a->id, 'assigned_user_id' => $a->user_id, 'status' => 'active']);
    $e2 = routerWaiting(['priority' => 'returning', 'reserved_user_id' => $a->user_id]);
    app(QueueRouter::class)->run('t');
    expect($e2->fresh()->assigned_user_id)->toBe($b->user_id);
});

it('serves live customers before the overnight backlog and drains the backlog into gaps per member', function () {
    $shift = Shift::factory()->create();
    $a = routerMember($shift);
    $night = routerWaiting(['priority' => 'overnight', 'reserved_user_id' => $a->user_id, 'enqueued_at' => now()->subHours(8)]);
    $live = routerWaiting(['priority' => 'live']);
    app(QueueRouter::class)->run('t');
    expect($live->fresh()->window_no)->toBe(1)->and($night->fresh()->window_no)->toBe(2)->and($night->fresh()->rule)->toContain('الليل');
});

it('respects platform permissions and offline members', function () {
    $shift = Shift::factory()->create();
    $a = routerMember($shift, ['facebook']);
    $b = routerMember($shift);
    $b->user->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
    $e = routerWaiting();
    $e->conversation->update(['platform' => 'instagram']);
    expect(app(QueueRouter::class)->run('t'))->toBe(0)->and($e->fresh()->status)->toBe('waiting');
    $b->user->forceFill(['last_seen_at' => now()])->save();
    app(QueueRouter::class)->run('t');
    expect($e->fresh()->assigned_user_id)->toBe($b->user_id);
});

it('sends escalations to the shift leader and keeps them waiting when the leader is full', function () {
    $leader = User::factory()->create(['role' => 'supervisor', 'last_seen_at' => now()]);
    $shift = Shift::factory()->create(['leader_user_id' => $leader->id]);
    $lm = ShiftMember::factory()->for($shift)->create(['user_id' => $leader->id, 'windows_cap' => 1]);
    $e = routerWaiting(['priority' => 'escalation']);
    app(QueueRouter::class)->run('t');
    expect($e->fresh()->assigned_user_id)->toBe($leader->id);
    $e2 = routerWaiting(['priority' => 'escalation']);
    app(QueueRouter::class)->run('t');
    expect($e2->fresh()->status)->toBe('waiting');
});

it('logs decision lines', function () {
    $shift = Shift::factory()->create();
    routerMember($shift);
    routerWaiting();
    app(QueueRouter::class)->run('اختبار');
    expect(QueueDecision::latest('id')->first()->lines)->toBeArray()->and(QueueDecision::latest('id')->first()->trigger)->toBe('اختبار');
});

it('routes an overnight entry to anyone when its reserved member is not serving', function () {
    $shift = Shift::factory()->create();
    $a = routerMember($shift, attrs: ['status' => 'break']);
    $b = routerMember($shift);
    $night = routerWaiting(['priority' => 'overnight', 'reserved_user_id' => $a->user_id]);
    app(QueueRouter::class)->run('t');
    expect($night->fresh()->assigned_user_id)->toBe($b->user_id)->and($night->fresh()->status)->toBe('active');
});

it('broadcasts the assignment to the moderator and the decision to the board', function () {
    Event::fake([QueueAssigned::class, RouterDecided::class, QueueEntryUpdated::class]);
    $shift = Shift::factory()->create();
    $a = routerMember($shift);
    $e = routerWaiting();
    app(QueueRouter::class)->run('t');
    Event::assertDispatched(QueueAssigned::class, fn ($ev) => $ev->broadcastOn()[0]->name === 'private-user.'.$a->user_id
        && $ev->broadcastWith() === ['entry_id' => $e->id, 'conversation_id' => $e->conversation_id, 'ticket' => $e->ticket_no, 'window_no' => 1, 'bot_summary' => null]);
    Event::assertDispatched(RouterDecided::class, fn ($ev) => $ev->broadcastWith()['trigger'] === 't' && is_array($ev->broadcastWith()['lines']));
    Event::assertDispatched(QueueEntryUpdated::class, fn ($ev) => $ev->broadcastWith()['ticket'] === $e->ticket_no && $ev->broadcastWith()['silence_left_seconds'] === null); // no moderator reply yet: the silence clock is not running
});

it('exposes the assignee and the open queue ticket on the conversation resource', function () {
    $shift = Shift::factory()->create();
    $a = routerMember($shift);
    $e = routerWaiting();
    app(QueueRouter::class)->run('t');
    $data = (new ConversationResource($e->conversation->fresh()))->resolve(request());
    expect($data['assignee'])->toBe(['id' => $a->user_id, 'name' => $a->user->name])
        ->and($data['queue_entry'])->toMatchArray(['ticket' => $e->ticket_no, 'window_no' => 1, 'status' => 'active', 'kind' => 'unknown']);
});

it('creates the queue.assigned notification only after the surrounding transaction commits', function () {
    $shift = Shift::factory()->create();
    $a = routerMember($shift);
    routerWaiting();
    DB::transaction(function () use ($a) {
        expect(app(QueueRouter::class)->run('t'))->toBe(1)
            ->and(UserNotification::where('user_id', $a->user_id)->where('type', 'queue.assigned')->exists())->toBeFalse();
    });
    expect(UserNotification::where('user_id', $a->user_id)->where('type', 'queue.assigned')->count())->toBe(1);
});

it('counts open windows per user across shift-member rows at the shift handover', function () {
    $u = User::factory()->create(['last_seen_at' => now()]);
    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $p) {
        $u->userPlatforms()->create(['platform' => $p]);
    }
    $early = Shift::factory()->create(['shift_key' => 'early', 'status' => 'closed', 'starts_at' => now()->subHours(8), 'ends_at' => now()->subMinute()]);
    $old = ShiftMember::factory()->for($early)->create(['user_id' => $u->id, 'status' => 'left']);
    QueueEntry::factory()->create(['shift_member_id' => $old->id, 'shift_id' => $early->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1]);
    $shift = Shift::factory()->create();
    ShiftMember::factory()->for($shift)->create(['user_id' => $u->id]);
    $first = routerWaiting();
    $second = routerWaiting();
    expect(app(QueueRouter::class)->run('t'))->toBe(1)
        ->and($first->fresh()->window_no)->toBe(2)->and($first->fresh()->assigned_user_id)->toBe($u->id)
        ->and($second->fresh()->status)->toBe('waiting');
});

it('prefers members under the occupancy cap', function () {
    $shift = Shift::factory()->create(['opened_at' => now()->subHour()]);
    $busy = routerMember($shift);
    $fresh = routerMember($shift);
    // Without the cap $busy wins the tiebreak (fewer entries this shift); 3 h handled in a 1 h shift puts her over 80 %.
    QueueEntry::factory()->create(['shift_member_id' => $busy->id, 'assigned_user_id' => $busy->user_id, 'status' => 'closed', 'handle_seconds' => 3 * 3600]);
    QueueEntry::factory()->count(3)->create(['shift_member_id' => $fresh->id, 'assigned_user_id' => $fresh->user_id, 'status' => 'closed', 'handle_seconds' => 60]);
    $e = routerWaiting();
    app(QueueRouter::class)->run('t');
    expect($e->fresh()->assigned_user_id)->toBe($fresh->user_id);
});

it('reuses a freed window number', function () {
    $shift = Shift::factory()->create();
    $a = routerMember($shift);
    $one = routerWaiting();
    $two = routerWaiting();
    app(QueueRouter::class)->run('t');
    expect($one->fresh()->window_no)->toBe(1)->and($two->fresh()->window_no)->toBe(2);
    $one->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    $three = routerWaiting();
    app(QueueRouter::class)->run('t');
    expect($three->fresh()->window_no)->toBe(1)->and($three->fresh()->assigned_user_id)->toBe($a->user_id);
});

it('sends an escalation to a supervisor with access when the leader cannot serve the platform', function () {
    $leaderUser = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $leaderUser->userPlatforms()->create(['platform' => 'facebook']);
    $shift = Shift::factory()->create(['leader_user_id' => $leaderUser->id]);
    ShiftMember::factory()->for($shift)->create(['user_id' => $leaderUser->id]);
    $sup = User::factory()->create(['role' => 'supervisor', 'last_seen_at' => now()]);
    ShiftMember::factory()->for($shift)->create(['user_id' => $sup->id]);
    $e = routerWaiting(['priority' => 'escalation']);
    $e->conversation->update(['platform' => 'instagram']);
    app(QueueRouter::class)->run('t');
    expect($e->fresh()->assigned_user_id)->toBe($sup->id);
});

it('alerts supervisors once when an escalation cannot be taken', function () {
    $leader = User::factory()->create(['role' => 'supervisor', 'last_seen_at' => now()]);
    $shift = Shift::factory()->create(['leader_user_id' => $leader->id]);
    ShiftMember::factory()->for($shift)->create(['user_id' => $leader->id, 'windows_cap' => 1]);
    routerWaiting(['priority' => 'escalation']);
    $stuck = routerWaiting(['priority' => 'escalation']);
    app(QueueRouter::class)->run('t');
    app(QueueRouter::class)->run('t');
    expect($stuck->fresh()->status)->toBe('waiting')
        ->and(UserNotification::where('type', 'queue.escalation_waiting')->where('user_id', $leader->id)->count())->toBe(1)
        ->and(UserNotification::where('type', 'queue.escalation_waiting')->first()->data['entry_id'])->toBe($stuck->id);
});

it('refuses to assign an entry that stopped waiting after it was read', function (array $now) {
    $shift = Shift::factory()->create();
    $a = routerMember($shift);
    $other = User::factory()->create();
    $stale = routerWaiting();
    QueueEntry::query()->whereKey($stale->id)->update(['assigned_user_id' => $now['status'] === 'active' ? $other->id : null, 'window_no' => $now['status'] === 'active' ? 1 : null] + $now);

    expect($stale->status)->toBe('waiting')
        ->and(app(QueueRouter::class)->assign($stale, $a, 'قاعدة', 'live'))->toBeFalse();

    $fresh = $stale->fresh();
    expect($fresh->status)->toBe($now['status'])
        ->and($fresh->assigned_user_id)->toBe($now['status'] === 'active' ? $other->id : null)
        ->and($fresh->conversation->assignee_id)->toBeNull()
        ->and($a->fresh()->status)->toBe('available')
        ->and(UserNotification::where('type', 'queue.assigned')->count())->toBe(0);
})->with([
    'cancelled' => [['status' => 'cancelled', 'close_reason' => 'cancelled']],
    'given to somebody else' => [['status' => 'active']],
]);

it('skips an entry cancelled between the selection and the assignment and gives the window to the next customer', function () {
    Event::fake([QueueAssigned::class]);
    $shift = Shift::factory()->create();
    $a = routerMember($shift, attrs: ['windows_cap' => 1]);
    $gone = routerWaiting();
    $next = routerWaiting();
    // The pass has read the lounge (her conversation is loaded right after the entries); she leaves now.
    $left = false;
    Conversation::retrieved(function (Conversation $c) use ($gone, &$left) {
        if (! $left && $c->id === $gone->conversation_id) {
            $left = true;
            QueueEntry::query()->whereKey($gone->id)->update(['status' => 'cancelled', 'close_reason' => 'cancelled']);
        }
    });

    expect(app(QueueRouter::class)->run('t'))->toBe(1);

    expect($left)->toBeTrue()
        ->and($gone->fresh()->status)->toBe('cancelled')
        ->and($gone->fresh()->assigned_user_id)->toBeNull()
        ->and($gone->conversation->fresh()->assignee_id)->toBeNull()
        ->and($next->fresh()->status)->toBe('active')
        ->and($next->fresh()->assigned_user_id)->toBe($a->user_id)
        ->and($next->fresh()->window_no)->toBe(1)
        ->and(UserNotification::where('type', 'queue.assigned')->count())->toBe(1)
        ->and(QueueDecision::latest('id')->first()->lines)->toContain('<span class="no">#'.$gone->ticket_no.': خرجت من الصالة قبل التسليم</span>');
    Event::assertDispatchedTimes(QueueAssigned::class, 1);
});

it('keeps the assignments already made when a later one fails', function () {
    $shift = Shift::factory()->create();
    $a = routerMember($shift);
    $first = routerWaiting();
    $second = routerWaiting();
    QueueEntry::updating(function (QueueEntry $e) use ($second) {
        if ($e->id === $second->id) {
            throw new RuntimeException('boom');
        }
    });
    Exceptions::fake();

    expect(app(QueueRouter::class)->run('t'))->toBe(1);

    expect($first->fresh()->status)->toBe('active')->and($first->fresh()->assigned_user_id)->toBe($a->user_id)
        ->and($second->fresh()->status)->toBe('waiting')->and($second->conversation->fresh()->assignee_id)->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});

it('writes no decision line when nobody is waiting', function () {
    $shift = Shift::factory()->create();
    routerMember($shift);

    expect(app(QueueRouter::class)->run('t'))->toBe(0)->and(QueueDecision::count())->toBe(0);
});

it('refuses a member who stopped serving between the selection and the assignment', function (Closure $change) {
    $shift = Shift::factory()->create();
    $a = routerMember($shift);
    $e = routerWaiting();
    $snapshot = ShiftMember::query()->with('user')->find($a->id); // what the pass read
    $change($a);

    expect(app(QueueRouter::class)->assign($e, $snapshot, 'قاعدة', 'live'))->toBeFalse();

    $fresh = $e->fresh();
    expect($fresh->status)->toBe('waiting')
        ->and($fresh->assigned_user_id)->toBeNull()
        ->and($fresh->window_no)->toBeNull()
        ->and($fresh->conversation->assignee_id)->toBeNull()
        ->and($a->fresh()->status)->not->toBe('busy')
        ->and(UserNotification::where('type', 'queue.assigned')->count())->toBe(0);
})->with([
    'went on break' => [fn (ShiftMember $m) => $m->update(['status' => 'break', 'break_started_at' => now(), 'break_ends_at' => now()->addMinutes(30)])],
    'asked for her break' => [fn (ShiftMember $m) => $m->update(['status' => 'pending_break'])],
    'went offline' => [fn (ShiftMember $m) => $m->update(['status' => 'offline'])],
    'left the shift' => [fn (ShiftMember $m) => $m->update(['status' => 'left', 'left_at' => now()])],
    'shift closed' => [fn (ShiftMember $m) => $m->shift->update(['status' => 'closed', 'closed_at' => now()])],
    'account deactivated' => [fn (ShiftMember $m) => $m->user->forceFill(['is_active' => false])->save()],
]);

it('refuses a member who reached her cap, counted per user across shift-member rows', function () {
    $early = Shift::factory()->create(['shift_key' => 'early', 'status' => 'closed']);
    $shift = Shift::factory()->create();
    $a = routerMember($shift); // cap 2 from the settings
    $old = ShiftMember::factory()->for($early)->create(['user_id' => $a->user_id, 'status' => 'left']);
    QueueEntry::factory()->create(['shift_member_id' => $old->id, 'assigned_user_id' => $a->user_id, 'status' => 'active', 'window_no' => 1]);
    $first = routerWaiting();
    $second = routerWaiting();

    expect(app(QueueRouter::class)->assign($first, $a, 'قاعدة', 'live'))->toBeTrue()
        ->and($first->fresh()->window_no)->toBe(2)
        ->and(app(QueueRouter::class)->assign($second, $a, 'قاعدة', 'live'))->toBeFalse()
        ->and($second->fresh()->status)->toBe('waiting')
        ->and($second->fresh()->assigned_user_id)->toBeNull()
        ->and(UserNotification::where('type', 'queue.assigned')->count())->toBe(1);
});

it('respects her own windows cap over the default when re-checking', function () {
    $shift = Shift::factory()->create();
    $a = routerMember($shift, attrs: ['windows_cap' => 1]);
    $first = routerWaiting();
    $second = routerWaiting();

    expect(app(QueueRouter::class)->assign($first, $a, 'قاعدة', 'live'))->toBeTrue()
        ->and(app(QueueRouter::class)->assign($second, $a, 'قاعدة', 'live'))->toBeFalse()
        ->and($second->fresh()->status)->toBe('waiting');
});

it('leaves the customer waiting and gives her to somebody else when the chosen member went on break during the pass', function () {
    $shift = Shift::factory()->create();
    $a = routerMember($shift);
    $b = routerMember($shift);
    QueueEntry::factory()->create(['shift_member_id' => $b->id, 'assigned_user_id' => $b->user_id, 'status' => 'active', 'window_no' => 1]);
    $e = routerWaiting();
    $next = routerWaiting();
    // The pass has read the desks; the least loaded one goes on break before the assignment.
    // (Her conversation is read once with the lounge and a second time under the lock in assign().)
    $seen = 0;
    Conversation::retrieved(function (Conversation $c) use ($a, $e, &$seen) {
        if ($c->id === $e->conversation_id && ++$seen === 2) {
            ShiftMember::query()->whereKey($a->id)->update(['status' => 'break']);
        }
    });

    expect(app(QueueRouter::class)->run('t'))->toBe(1);

    expect($e->fresh()->status)->toBe('waiting')
        ->and($next->fresh()->assigned_user_id)->toBe($b->user_id)
        ->and($a->fresh()->status)->toBe('break')
        ->and(QueueEntry::query()->where('assigned_user_id', $a->user_id)->count())->toBe(0);

    ShiftMember::query()->whereKey($a->id)->update(['status' => 'available']);
    app(QueueRouter::class)->run('t');
    expect($e->fresh()->status)->toBe('active');
});
