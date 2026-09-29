<?php
use App\Models\{Conversation, QueueEntry, QueueSetting, Shift, ShiftMember, User, UserNotification};
use App\Queue\QueueRouter;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true, 'windows_per_moderator' => 2]);
});

function member(Shift $shift, array $platforms = ['facebook', 'instagram', 'whatsapp', 'tiktok'], array $attrs = []): ShiftMember {
    $u = User::factory()->create(['last_seen_at' => now()]);
    foreach ($platforms as $p) { $u->userPlatforms()->create(['platform' => $p]); }
    return ShiftMember::factory()->for($shift)->create(['user_id' => $u->id] + $attrs);
}
function waiting(array $attrs = []): QueueEntry {
    static $n = 100;
    return QueueEntry::factory()->create(['ticket_no' => ++$n, 'enqueued_at' => now()->subSeconds(1000 - $n)] + $attrs);
}

it('routes live entries to the least loaded member and sets the window', function () {
    $shift = Shift::factory()->create(); $a = member($shift); $b = member($shift);
    QueueEntry::factory()->create(['shift_member_id' => $a->id, 'assigned_user_id' => $a->user_id, 'status' => 'active', 'window_no' => 1]);
    $e = waiting();
    expect(app(QueueRouter::class)->run('test'))->toBe(1);
    $e->refresh();
    expect($e->assigned_user_id)->toBe($b->user_id)->and($e->window_no)->toBe(1)->and($e->status)->toBe('active')->and($e->rule)->toContain('الأقل حملاً')
        ->and($e->conversation->fresh()->assignee_id)->toBe($b->user_id)
        ->and(UserNotification::where('user_id', $b->user_id)->where('type', 'queue.assigned')->exists())->toBeTrue();
});

it('returns a returning customer to the same member when she has a free window, else to anyone', function () {
    $shift = Shift::factory()->create(); $a = member($shift); $b = member($shift);
    $e = waiting(['priority' => 'returning', 'reserved_user_id' => $a->user_id]);
    app(QueueRouter::class)->run('t');
    expect($e->fresh()->assigned_user_id)->toBe($a->user_id)->and($e->fresh()->rule)->toContain('نفس الموظفة');
    QueueEntry::factory()->count(2)->create(['shift_member_id' => $a->id, 'assigned_user_id' => $a->user_id, 'status' => 'active']);
    $e2 = waiting(['priority' => 'returning', 'reserved_user_id' => $a->user_id]);
    app(QueueRouter::class)->run('t');
    expect($e2->fresh()->assigned_user_id)->toBe($b->user_id);
});

it('serves live customers before the overnight backlog and drains the backlog into gaps per member', function () {
    $shift = Shift::factory()->create(); $a = member($shift);
    $night = waiting(['priority' => 'overnight', 'reserved_user_id' => $a->user_id, 'enqueued_at' => now()->subHours(8)]);
    $live = waiting(['priority' => 'live']);
    app(QueueRouter::class)->run('t');
    expect($live->fresh()->window_no)->toBe(1)->and($night->fresh()->window_no)->toBe(2)->and($night->fresh()->rule)->toContain('الليل');
});

it('respects platform permissions, offline members and the occupancy cap', function () {
    $shift = Shift::factory()->create(); $a = member($shift, ['facebook']); $b = member($shift);
    $b->user->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
    $e = waiting(); $e->conversation->update(['platform' => 'instagram']);
    expect(app(QueueRouter::class)->run('t'))->toBe(0)->and($e->fresh()->status)->toBe('waiting');
    $b->user->forceFill(['last_seen_at' => now()])->save();
    app(QueueRouter::class)->run('t');
    expect($e->fresh()->assigned_user_id)->toBe($b->user_id);
});

it('sends escalations to the shift leader and keeps them waiting when the leader is full', function () {
    $leader = User::factory()->create(['role' => 'supervisor', 'last_seen_at' => now()]);
    $shift = Shift::factory()->create(['leader_user_id' => $leader->id]);
    $lm = ShiftMember::factory()->for($shift)->create(['user_id' => $leader->id, 'windows_cap' => 1]);
    $e = waiting(['priority' => 'escalation']);
    app(QueueRouter::class)->run('t');
    expect($e->fresh()->assigned_user_id)->toBe($leader->id);
    $e2 = waiting(['priority' => 'escalation']);
    app(QueueRouter::class)->run('t');
    expect($e2->fresh()->status)->toBe('waiting');
});

it('logs decision lines', function () {
    $shift = Shift::factory()->create(); member($shift); waiting();
    app(QueueRouter::class)->run('اختبار');
    expect(\App\Models\QueueDecision::latest('id')->first()->lines)->toBeArray()->and(\App\Models\QueueDecision::latest('id')->first()->trigger)->toBe('اختبار');
});

it('routes an overnight entry to anyone when its reserved member is not serving', function () {
    $shift = Shift::factory()->create(); $a = member($shift, attrs: ['status' => 'break']); $b = member($shift);
    $night = waiting(['priority' => 'overnight', 'reserved_user_id' => $a->user_id]);
    app(QueueRouter::class)->run('t');
    expect($night->fresh()->assigned_user_id)->toBe($b->user_id)->and($night->fresh()->status)->toBe('active');
});

it('broadcasts the assignment to the moderator and the decision to the board', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Queue\Events\QueueAssigned::class, \App\Queue\Events\RouterDecided::class, \App\Queue\Events\QueueEntryUpdated::class]);
    $shift = Shift::factory()->create(); $a = member($shift); $e = waiting();
    app(QueueRouter::class)->run('t');
    \Illuminate\Support\Facades\Event::assertDispatched(\App\Queue\Events\QueueAssigned::class, fn ($ev) => $ev->broadcastOn()[0]->name === 'private-user.'.$a->user_id
        && $ev->broadcastWith() === ['entry_id' => $e->id, 'conversation_id' => $e->conversation_id, 'ticket' => $e->ticket_no, 'window_no' => 1, 'bot_summary' => null]);
    \Illuminate\Support\Facades\Event::assertDispatched(\App\Queue\Events\RouterDecided::class, fn ($ev) => $ev->broadcastWith()['trigger'] === 't' && is_array($ev->broadcastWith()['lines']));
    \Illuminate\Support\Facades\Event::assertDispatched(\App\Queue\Events\QueueEntryUpdated::class, fn ($ev) => $ev->broadcastWith()['ticket'] === $e->ticket_no && $ev->broadcastWith()['silence_left_seconds'] === 300);
});

it('exposes the assignee and the open queue ticket on the conversation resource', function () {
    $shift = Shift::factory()->create(); $a = member($shift); $e = waiting();
    app(QueueRouter::class)->run('t');
    $data = (new \App\Http\Resources\ConversationResource($e->conversation->fresh()))->resolve(request());
    expect($data['assignee'])->toBe(['id' => $a->user_id, 'name' => $a->user->name])
        ->and($data['queue_entry'])->toMatchArray(['ticket' => $e->ticket_no, 'window_no' => 1, 'status' => 'active', 'kind' => 'unknown']);
});
