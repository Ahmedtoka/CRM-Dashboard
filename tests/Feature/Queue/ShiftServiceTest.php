<?php

use App\Http\Resources\ShiftMemberResource;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\Data\HandoverContext;
use App\Queue\QueueService;
use App\Queue\ShiftService;
use App\Queue\WindowLifecycle;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

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
    $shift = Shift::factory()->create();
    $m = ShiftMember::factory()->for($shift)->create(['status' => 'busy']);
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
