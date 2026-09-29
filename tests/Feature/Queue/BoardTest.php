<?php

use App\Models\QueueDecision;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\BoardState;
use App\Queue\Events\QueueMemberUpdated;
use App\Queue\Events\RouterDecided;
use App\Queue\QueueRouter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

function boardSupervisor(string $role = 'supervisor'): User
{
    return User::factory()->create(['role' => $role, 'last_seen_at' => now()]);
}

/** A moderator who may serve every platform, online now. */
function boardModerator(array $attrs = []): User
{
    $u = User::factory()->create($attrs + ['role' => 'moderator', 'last_seen_at' => now()]);

    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $platform) {
        $u->userPlatforms()->create(['platform' => $platform]);
    }

    return $u;
}

function boardDesk(Shift $shift, ?User $user = null, array $attrs = []): ShiftMember
{
    return ShiftMember::factory()->for($shift)->create($attrs + ['user_id' => ($user ?? boardModerator())->id]);
}

function boardWaiting(array $attrs = []): QueueEntry
{
    $e = QueueEntry::factory()->create($attrs);
    $e->conversation->update(['queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true]);

    return $e;
}

function boardWindow(ShiftMember $m, array $attrs = []): QueueEntry
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

/** Every board endpoint, with a body that would pass validation. @return list<array{0: string, 1: string, 2: array}> */
function boardEndpoints(Shift $shift, ShiftMember $m, QueueEntry $waiting): array
{
    return [
        ['get', '/board/state', []],
        ['post', '/board/start', ['roster' => ['morning' => [$m->user_id]]]],
        ['post', "/board/shifts/{$shift->id}/members", ['user_id' => $m->user_id]],
        ['delete', "/board/members/{$m->id}", []],
        ['post', "/board/members/{$m->id}/status", ['status' => 'break']],
        ['post', "/board/entries/{$waiting->id}/assign", ['user_id' => $m->user_id]],
        ['post', "/board/entries/{$waiting->id}/cancel", ['reason' => 'تجربة']],
    ];
}

// ───── who may use the board ─────

it('shows the board page to a supervisor, an admin and the leader of the open shift', function () {
    $leader = boardModerator();
    Shift::factory()->create(['leader_user_id' => $leader->id]);

    foreach ([boardSupervisor(), boardSupervisor('admin'), $leader] as $user) {
        $this->actingAs($user)->get('/board')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Board')->where('enabled', true)->where('canSeeBoard', true));
        $this->actingAs($user)->getJson('/board/state')->assertOk()->assertJsonPath('data.enabled', true);
    }
});

it('keeps a moderator out of the page and of every endpoint', function () {
    $shift = Shift::factory()->create();
    $m = boardDesk($shift);
    $waiting = boardWaiting();
    $m->user->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($m->user)->get('/board')->assertForbidden();
    $this->actingAs($m->user)->get('/inbox')->assertInertia(fn (AssertableInertia $page) => $page->where('canSeeBoard', false));

    foreach (boardEndpoints($shift, $m, $waiting) as [$method, $url, $body]) {
        $this->actingAs($m->user)->json($method, $url, $body)->assertForbidden()
            ->assertJsonPath('message', 'اللوحة الحية للمشرفات وليدر الشيفت بس.');
    }

    expect($waiting->fresh()->status)->toBe('waiting')->and($m->fresh()->status)->toBe('available');
});

it('does not let the leader of a closed shift in, and lets a template leader start the day when no shift is open', function () {
    $old = boardModerator();
    Shift::factory()->create(['shift_key' => 'early', 'status' => 'closed', 'leader_user_id' => $old->id]);
    $this->actingAs($old)->getJson('/board/state')->assertForbidden();

    $next = boardModerator();
    $templates = QueueSetting::DEFAULT_SHIFTS;
    $templates[0]['leader_user_id'] = $next->id;
    QueueSetting::current()->update(['shifts' => $templates]);

    $this->actingAs($next)->getJson('/board/state')->assertOk();
    $this->actingAs($next)->postJson('/board/start', ['roster' => ['morning' => [$old->id]]])->assertOk();

    // A shift is open now and she leads it (the template's leader): still allowed; the other moderator is not.
    expect(Shift::where('status', 'open')->first()->leader_user_id)->toBe($next->id);
    $this->actingAs($next)->getJson('/board/state')->assertOk();
    $this->actingAs($old)->getJson('/board/state')->assertForbidden();
});

it('asks a guest to sign in', function () {
    $this->getJson('/board/state')->assertUnauthorized();
    $this->get('/board')->assertRedirect();
});

// ───── queue disabled ─────

it('explains itself and refuses every action while the queue is off', function () {
    $shift = Shift::factory()->create();
    $m = boardDesk($shift);
    $waiting = boardWaiting();
    $sup = boardSupervisor();
    QueueSetting::current()->update(['enabled' => false]);

    $this->actingAs($sup)->get('/board')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Board')->where('enabled', false)->where('canEditSettings', true));

    DB::enableQueryLog();
    $this->actingAs($sup)->getJson('/board/state')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonMissingPath('data.members');
    $reads = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'queue_entries') || str_contains($q, 'shift'));
    DB::disableQueryLog();
    expect($reads)->toBeEmpty();

    foreach (array_slice(boardEndpoints($shift, $m, $waiting), 1) as [$method, $url, $body]) {
        $this->actingAs($sup)->json($method, $url, $body)->assertStatus(409)->assertJsonPath('message', __('errors.queue.disabled'));
    }

    expect($waiting->fresh()->status)->toBe('waiting')->and($m->fresh()->status)->toBe('available');
});

// ───── the start of the day ─────

it('starts the day: today\'s shifts, the roster, the leader at her desk, and the shift that covers now is open', function () {
    $sup = boardSupervisor();
    [$a, $b, $c, $leader] = [boardModerator(), boardModerator(), boardModerator(), boardModerator()];

    $response = $this->actingAs($sup)->postJson('/board/start', [
        'roster' => ['morning' => [$a->id, $b->id], 'evening' => [$c->id]],
        'leaders' => ['morning' => $leader->id, 'evening' => null],
    ])->assertOk();

    $morning = Shift::where('shift_key', 'morning')->first();
    $evening = Shift::where('shift_key', 'evening')->first();

    expect($morning->status)->toBe('open')->and($morning->leader_user_id)->toBe($leader->id)->and($morning->opened_by_id)->toBe($sup->id)
        ->and($evening->status)->toBe('planned')->and($evening->leader_user_id)->toBeNull()
        ->and($morning->members()->pluck('user_id')->sort()->values()->all())->toBe(collect([$a->id, $b->id, $leader->id])->sort()->values()->all())
        ->and($evening->members()->pluck('user_id')->all())->toBe([$c->id])
        ->and(QueueSetting::current()->default_roster['evening'])->toBe([$c->id]);

    $response->assertJsonPath('data.shift.id', $morning->id)
        ->assertJsonPath('data.shift.leader.id', $leader->id)
        ->assertJsonCount(3, 'data.members')
        ->assertJsonCount(2, 'data.shifts');
    expect(collect($response->json('data.members'))->firstWhere('user.id', $leader->id)['is_leader'])->toBeTrue();
});

it('starts the day when a moderator is ticked twice in one shift, and seats her once', function () {
    $sup = boardSupervisor();
    [$a, $leader] = [boardModerator(), boardModerator()];

    $this->actingAs($sup)->postJson('/board/start', [
        'roster' => ['morning' => [$a->id, $leader->id, $a->id], 'evening' => []],
        'leaders' => ['morning' => $leader->id, 'evening' => null],
    ])->assertOk();

    $morning = Shift::where('shift_key', 'morning')->first();
    expect($morning->members()->pluck('user_id')->sort()->values()->all())->toBe(collect([$a->id, $leader->id])->sort()->values()->all());
});

it('keeps a moderator\'s desk as it is when the day is started again', function () {
    $sup = boardSupervisor();
    $a = boardModerator();
    $this->actingAs($sup)->postJson('/board/start', ['roster' => ['morning' => [$a->id]]])->assertOk();
    $desk = ShiftMember::where('user_id', $a->id)->first();
    $desk->update(['status' => 'break', 'break_ends_at' => now()->addMinutes(10)]);

    $b = boardModerator();
    $this->actingAs($sup)->postJson('/board/start', ['roster' => ['morning' => [$a->id, $b->id]]])->assertOk()->assertJsonCount(2, 'data.members');

    expect($desk->fresh()->status)->toBe('break')->and(Shift::where('status', 'open')->count())->toBe(1);
});

it('validates the start of the day', function (array $body, string $messageKey) {
    $gone = User::factory()->create(['is_active' => false]);
    $body = json_decode(str_replace(['"GONE"', '"MOD"'], [(string) $gone->id, (string) boardModerator()->id], json_encode($body)), true);

    $response = $this->actingAs(boardSupervisor())->postJson('/board/start', $body)->assertStatus(422);

    expect(json_encode($response->json(), JSON_UNESCAPED_UNICODE))->toContain(__($messageKey));
    expect(Shift::where('status', 'open')->count())->toBe(0)->and(ShiftMember::count())->toBe(0);
})->with([
    'no roster' => [[], 'errors.queue.roster_empty'],
    'nobody ticked' => [['roster' => ['morning' => [], 'evening' => []]], 'errors.queue.roster_empty'],
    'a shift that is not in the settings' => [['roster' => ['night' => ['MOD']]], 'errors.queue.unknown_shift'],
    'a deactivated user' => [['roster' => ['morning' => ['GONE']]], 'errors.queue.user_unavailable'],
    'a deactivated leader' => [['roster' => ['morning' => []], 'leaders' => ['morning' => 'GONE']], 'errors.queue.user_unavailable'],
]);

it('leaves no half roster behind when the start fails half-way', function () {
    $a = boardModerator();

    $this->actingAs(boardSupervisor())->postJson('/board/start', ['roster' => ['morning' => [$a->id, 999999]]])->assertStatus(422);

    expect(ShiftMember::count())->toBe(0);
});

// ───── the roster during the day ─────

it('adds a moderator to the open shift and a customer who was waiting goes to her', function () {
    $shift = Shift::factory()->create();
    $waiting = boardWaiting();
    $u = boardModerator();

    $this->actingAs(boardSupervisor())->postJson("/board/shifts/{$shift->id}/members", ['user_id' => $u->id, 'windows_cap' => 2])
        ->assertOk()->assertJsonCount(1, 'data.members')->assertJsonPath('data.members.0.cap', 2)->assertJsonPath('data.members.0.user.id', $u->id);

    expect($waiting->fresh()->assigned_user_id)->toBe($u->id)->and(ShiftMember::where('user_id', $u->id)->first()->break_at)->not->toBeNull();
});

it('changes only the number of windows of a moderator who is already at her desk', function () {
    $shift = Shift::factory()->create();
    $m = boardDesk($shift, attrs: ['status' => 'break', 'break_ends_at' => now()->addMinutes(5)]);
    Event::fake([QueueMemberUpdated::class]);

    $this->actingAs(boardSupervisor())->postJson("/board/shifts/{$shift->id}/members", ['user_id' => $m->user_id, 'windows_cap' => 5])->assertOk();

    expect($m->fresh()->windows_cap)->toBe(5)->and($m->fresh()->status)->toBe('break')->and(ShiftMember::count())->toBe(1);
    Event::assertDispatched(QueueMemberUpdated::class);
});

it('validates a new desk and refuses a closed shift', function () {
    $sup = boardSupervisor();
    $shift = Shift::factory()->create();
    $gone = User::factory()->create(['is_active' => false]);

    $this->actingAs($sup)->postJson("/board/shifts/{$shift->id}/members", [])->assertStatus(422)->assertJsonValidationErrors('user_id');
    $this->actingAs($sup)->postJson("/board/shifts/{$shift->id}/members", ['user_id' => $gone->id])->assertStatus(422)
        ->assertJsonPath('errors.user_id.0', __('errors.queue.user_unavailable'));
    $this->actingAs($sup)->postJson("/board/shifts/{$shift->id}/members", ['user_id' => boardModerator()->id, 'windows_cap' => 11])->assertStatus(422)
        ->assertJsonValidationErrors('windows_cap');

    $shift->update(['status' => 'closed']);
    $this->actingAs($sup)->postJson("/board/shifts/{$shift->id}/members", ['user_id' => boardModerator()->id])->assertStatus(409)
        ->assertJsonPath('message', __('errors.queue.shift_closed'));
    $this->actingAs($sup)->postJson('/board/shifts/999999/members', ['user_id' => boardModerator()->id])->assertNotFound();

    expect(ShiftMember::count())->toBe(0);
});

it('removes a moderator and sends her customers back to the lounge with their tickets', function () {
    $shift = Shift::factory()->create();
    $m = boardDesk($shift);
    $window = boardWindow($m, ['ticket_no' => 41]);

    $this->actingAs(boardSupervisor())->deleteJson("/board/members/{$m->id}")->assertOk()
        ->assertJsonCount(0, 'data.members')->assertJsonCount(0, 'data.open')->assertJsonCount(1, 'data.waiting')
        ->assertJsonPath('data.waiting.0.ticket', 41)->assertJsonPath('data.waiting.0.priority', 'returning');

    expect($m->fresh()->status)->toBe('left')->and($window->fresh()->close_reason)->toBe('transfer');

    $this->actingAs(boardSupervisor())->deleteJson("/board/members/{$m->id}")->assertStatus(409)->assertJsonPath('message', __('errors.queue.member_gone'));
});

it('sends a moderator on her break and brings her back', function () {
    $shift = Shift::factory()->create();
    $m = boardDesk($shift);
    $sup = boardSupervisor();

    $this->actingAs($sup)->postJson("/board/members/{$m->id}/status", ['status' => 'break'])->assertOk()->assertJsonPath('data.members.0.status', 'break');
    expect($m->fresh()->break_ends_at)->not->toBeNull();

    $this->actingAs($sup)->postJson("/board/members/{$m->id}/status", ['status' => 'available'])->assertOk()->assertJsonPath('data.members.0.status', 'available');

    boardWindow($m);
    $this->actingAs($sup)->postJson("/board/members/{$m->id}/status", ['status' => 'break'])->assertOk()->assertJsonPath('data.members.0.status', 'pending_break');

    $this->actingAs($sup)->postJson("/board/members/{$m->id}/status", ['status' => 'offline'])->assertStatus(422)->assertJsonValidationErrors('status');
    $m->update(['status' => 'left']);
    $this->actingAs($sup)->postJson("/board/members/{$m->id}/status", ['status' => 'available'])->assertStatus(409);
});

// ───── manual assignment ─────

it('hands a waiting customer to a moderator with a free window, by hand', function () {
    $shift = Shift::factory()->create();
    $m = boardDesk($shift);
    $m->user->update(['last_seen_at' => null]); // the router would not pick her: a person may
    $waiting = boardWaiting(['ticket_no' => 50]);
    $sup = boardSupervisor();
    Event::fake([RouterDecided::class]);

    $this->actingAs($sup)->postJson("/board/entries/{$waiting->id}/assign", ['user_id' => $m->user_id])->assertOk()
        ->assertJsonCount(0, 'data.waiting')->assertJsonPath('data.open.0.id', $waiting->id)->assertJsonPath('data.open.0.window_no', 1)
        ->assertJsonPath('data.members.0.open_count', 1)->assertJsonPath('data.members.0.status', 'busy')
        ->assertJsonPath('data.last_call.ticket', 50)->assertJsonPath('data.last_call.name', $m->user->name);

    $fresh = $waiting->fresh();
    expect($fresh->assigned_user_id)->toBe($m->user_id)->and($fresh->status)->toBe('active')->and($fresh->rule)->toContain('يدوي')->toContain($sup->name)
        ->and($fresh->conversation->assignee_id)->toBe($m->user_id);

    $decision = QueueDecision::query()->latest('id')->first();
    expect($decision->trigger)->toContain('تعيين يدوي')->and(implode(' ', $decision->lines))->toContain('#50')->toContain(e($m->user->name));
    Event::assertDispatched(RouterDecided::class);
});

it('refuses a manual assignment to a moderator whose windows are all taken', function () {
    $shift = Shift::factory()->create();
    $m = boardDesk($shift, attrs: ['windows_cap' => 2]);
    boardWindow($m);
    boardWindow($m);
    $waiting = boardWaiting();

    $this->actingAs(boardSupervisor())->postJson("/board/entries/{$waiting->id}/assign", ['user_id' => $m->user_id])
        ->assertStatus(422)->assertJsonPath('message', __('errors.queue.member_full'));

    expect($waiting->fresh()->status)->toBe('waiting')->and($waiting->fresh()->assigned_user_id)->toBeNull();
});

it('refuses a manual assignment that cannot stand', function (string $case, int $status, string $messageKey) {
    $shift = Shift::factory()->create();
    $m = boardDesk($shift);
    $waiting = boardWaiting();
    $userId = $m->user_id;

    match ($case) {
        'break', 'offline', 'left' => $m->update(['status' => $case]),
        'stranger' => $userId = boardModerator()->id,
        'platform' => $m->user->userPlatforms()->delete(),
        'deactivated' => $m->user->update(['is_active' => false]),
        'gone' => $waiting->update(['status' => 'cancelled']),
    };

    $this->actingAs(boardSupervisor())->postJson("/board/entries/{$waiting->id}/assign", ['user_id' => $userId])
        ->assertStatus($status)->assertJsonPath('message', __($messageKey));

    expect($waiting->fresh()->assigned_user_id)->toBeNull();
})->with([
    'she is on her break' => ['break', 422, 'errors.queue.member_unavailable'],
    'she is offline' => ['offline', 422, 'errors.queue.member_unavailable'],
    'her account is deactivated' => ['deactivated', 422, 'errors.queue.member_unavailable'],
    'she left the shift' => ['left', 422, 'errors.queue.member_not_on_shift'],
    'she is not on the shift at all' => ['stranger', 422, 'errors.queue.member_not_on_shift'],
    'she may not serve the platform' => ['platform', 422, 'errors.queue.member_platform'],
    'the customer is no longer waiting' => ['gone', 409, 'errors.queue.not_waiting'],
]);

it('answers cleanly when the router refuses the assignment under its locks', function () {
    $shift = Shift::factory()->create();
    $m = boardDesk($shift);
    $waiting = boardWaiting();
    $this->mock(QueueRouter::class, function ($mock) {
        $mock->shouldReceive('openForUser')->andReturn(QueueEntry::query()->whereRaw('1 = 0'));
        $mock->shouldReceive('assign')->once()->andReturn(false);
    });

    $this->actingAs(boardSupervisor())->postJson("/board/entries/{$waiting->id}/assign", ['user_id' => $m->user_id])
        ->assertStatus(409)->assertJsonPath('message', __('errors.queue.member_unavailable'));

    expect(QueueDecision::count())->toBe(0);
});

it('needs a moderator for a manual assignment, and a shift that is open', function () {
    $waiting = boardWaiting();
    $sup = boardSupervisor();

    $this->actingAs($sup)->postJson("/board/entries/{$waiting->id}/assign", [])->assertStatus(422)->assertJsonValidationErrors('user_id');
    $this->actingAs($sup)->postJson("/board/entries/{$waiting->id}/assign", ['user_id' => boardModerator()->id])->assertStatus(422)
        ->assertJsonPath('message', __('errors.queue.member_not_on_shift'));
    $this->actingAs($sup)->postJson('/board/entries/999999/assign', ['user_id' => 1])->assertNotFound();
});

// ───── cancel ─────

it('takes a waiting customer out of the lounge with a reason', function () {
    Shift::factory()->create();
    $waiting = boardWaiting(['ticket_no' => 7]);
    $sup = boardSupervisor();

    $this->actingAs($sup)->postJson("/board/entries/{$waiting->id}/cancel", ['reason' => '  رسالة بالغلط  '])->assertOk()->assertJsonCount(0, 'data.waiting')
        ->assertJsonPath('data.kpis.closed.cancelled', 1)->assertJsonPath('data.kpis.waiting', 0);

    $fresh = $waiting->fresh();
    expect($fresh->status)->toBe('cancelled')->and($fresh->close_reason)->toBe('cancelled')->and($fresh->close_note)->toBe('رسالة بالغلط')
        ->and($fresh->closed_by_id)->toBe($sup->id)
        ->and(implode(' ', QueueDecision::query()->latest('id')->first()->lines))->toContain('#7')->toContain('رسالة بالغلط');
});

it('escapes what the manager typed before it reaches the wall screen', function () {
    $waiting = boardWaiting();

    $this->actingAs(boardSupervisor())->postJson("/board/entries/{$waiting->id}/cancel", ['reason' => '<img src=x onerror=alert(1)>'])->assertOk();

    expect(implode(' ', QueueDecision::query()->latest('id')->first()->lines))->not->toContain('<img')->toContain('&lt;img');
});

it('validates a cancel and leaves an open window alone', function () {
    $shift = Shift::factory()->create();
    $waiting = boardWaiting();
    $window = boardWindow(boardDesk($shift));
    $sup = boardSupervisor();
    $sup->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($sup)->postJson("/board/entries/{$waiting->id}/cancel", [])->assertStatus(422)->assertJsonPath('errors.reason.0', 'اكتبي سبب الإلغاء.');
    $this->actingAs($sup)->postJson("/board/entries/{$waiting->id}/cancel", ['reason' => str_repeat('ا', 201)])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->actingAs($sup)->postJson("/board/entries/{$window->id}/cancel", ['reason' => 'تجربة'])->assertStatus(409)
        ->assertJsonPath('message', 'العميلة دي مبقتش في صالة الانتظار.');

    expect($waiting->fresh()->status)->toBe('waiting')->and($window->fresh()->status)->toBe('active');
});

// ───── the state ─────

it('draws the room from real data', function () {
    $leader = boardSupervisor();
    $shift = Shift::factory()->create(['leader_user_id' => $leader->id, 'shift_key' => 'morning']);
    $a = boardDesk($shift);
    $b = boardDesk($shift, attrs: ['status' => 'break', 'break_ends_at' => now()->addMinutes(12), 'windows_cap' => 2]);
    boardDesk($shift, attrs: ['status' => 'left']);
    $boss = boardDesk($shift, $leader);

    $window = boardWindow($a, ['ticket_no' => 12, 'enqueued_at' => now()->subMinutes(9), 'first_reply_at' => now()->subMinutes(2), 'sla_met' => true, 'last_agent_message_at' => now()->subSeconds(100)]);
    $window->conversation->update(['last_customer_message_at' => now()->subSeconds(200)]);
    QueueEntry::factory()->create(['shift_member_id' => $a->id, 'assigned_user_id' => $a->user_id, 'status' => 'closed', 'close_reason' => 'inquiry', 'closed_at' => now(), 'first_reply_at' => now(), 'sla_met' => false]);
    QueueEntry::factory()->create(['shift_member_id' => $a->id, 'assigned_user_id' => $a->user_id, 'status' => 'closed', 'close_reason' => 'auto', 'closed_at' => now(), 'ticket_no' => 100003]);

    $live = boardWaiting(['ticket_no' => 20, 'enqueued_at' => now()->subMinutes(4), 'bot_summary' => ['topic' => 'عايزة أغير المقاس', 'lines' => ['أوردر 1234']]]);
    $night = boardWaiting(['ticket_no' => 3, 'priority' => 'overnight', 'enqueued_at' => now()->subHours(9), 'reserved_user_id' => $a->user_id]);
    $back = boardWaiting(['ticket_no' => 21, 'priority' => 'returning', 'enqueued_at' => now()->subMinute(), 'bot_summary' => ['topic' => null, 'lines' => ['', 'فين الشحنة؟']]]);
    QueueDecision::create(['shift_id' => $shift->id, 'trigger' => 'عميلة جديدة #20', 'lines' => ['<b>#20</b> مستنية'], 'created_at' => now()]);

    $data = $this->actingAs($leader)->getJson('/board/state')->assertOk()->json('data');

    expect($data['now'])->toBe(now()->toIso8601String())
        ->and($data['shift'])->toMatchArray(['id' => $shift->id, 'shift_key' => 'morning', 'status' => 'open'])
        ->and($data['shift']['leader'])->toMatchArray(['id' => $leader->id, 'name' => $leader->name])
        ->and(collect($data['members'])->pluck('id')->all())->toBe([$a->id, $b->id, $boss->id])
        ->and(collect($data['waiting'])->pluck('id')->all())->toBe([$back->id, $live->id, $night->id]) // the router's order
        ->and(collect($data['open'])->pluck('id')->all())->toBe([$window->id]);

    expect($data['members'][0])->toMatchArray(['status' => 'busy', 'cap' => 3, 'open_count' => 1, 'is_leader' => false, 'online' => true])
        ->and($data['members'][0]['today'])->toMatchArray(['received' => 3, 'inquiry' => 1, 'auto' => 1, 'problem' => 0])
        ->and($data['members'][0]['windows'][0])->toMatchArray(['entry_id' => $window->id, 'ticket' => 12, 'window_no' => 1, 'silence_left_seconds' => 200])
        ->and($data['members'][0]['platforms'])->toContain('facebook')
        ->and($data['members'][1])->toMatchArray(['status' => 'break', 'cap' => 2, 'open_count' => 0])
        ->and($data['members'][1]['break_ends_at'])->toBe(now()->addMinutes(12)->toIso8601String())
        ->and($data['members'][2]['is_leader'])->toBeTrue();

    expect($data['waiting'][1])->toMatchArray(['ticket' => 20, 'priority' => 'live', 'request_line' => 'عايزة أغير المقاس', 'status' => 'waiting'])
        ->and($data['waiting'][1]['customer']['name'])->toBe($live->conversation->customer->name)
        ->and($data['waiting'][1]['platform'])->toBe($live->conversation->platform->value)
        ->and($data['waiting'][0]['request_line'])->toBe('فين الشحنة؟')
        ->and($data['waiting'][2]['reserved_user_id'])->toBe($a->user_id)
        ->and($data['open'][0])->toMatchArray(['silence_left_seconds' => 200, 'assigned_user_id' => $a->user_id, 'window_no' => 1]);

    expect($data['kpis'])->toMatchArray([
        'issued' => 5, 'waiting' => 3, 'waiting_overnight' => 1, 'longest_wait_seconds' => 240, 'open' => 1, 'capacity' => 6,
        'sla_replied' => 2, 'sla_met' => 1, 'sla_pct' => 50, 'sla_target_pct' => 90, 'closed_manual' => 1, 'closed_total' => 2,
    ])->and($data['kpis']['closed'])->toMatchArray(['inquiry' => 1, 'auto' => 1, 'problem' => 0, 'case' => 0, 'cancelled' => 0]);

    expect($data['decisions'][0])->toMatchArray(['trigger' => 'عميلة جديدة #20', 'lines' => ['<b>#20</b> مستنية']])
        ->and($data['last_call'])->toMatchArray(['ticket' => 12, 'window_no' => 1, 'name' => $a->user->name])
        ->and($data['settings'])->toMatchArray(['windows_per_moderator' => 3, 'silence_warn_seconds' => 180, 'silence_close_seconds' => 300])
        ->and(collect($data['templates'])->pluck('opens_now', 'key')->all())->toBe(['morning' => true, 'evening' => false])
        ->and($data['templates'][0])->toMatchArray(['status' => 'open', 'shift_id' => $shift->id, 'leader_user_id' => $leader->id])
        ->and($data['templates'][1]['status'])->toBeNull()
        ->and(collect($data['users'])->pluck('id'))->toContain($a->user_id, $leader->id);
});

it('shows empty desks and the templates when no shift is open', function () {
    $waiting = boardWaiting(['priority' => 'overnight']);
    QueueSetting::current()->update(['default_roster' => ['morning' => [5]]]);
    User::factory()->create(['is_active' => false, 'name' => 'موقوفة']);

    $data = $this->actingAs(boardSupervisor())->getJson('/board/state')->assertOk()->json('data');

    expect($data['shift'])->toBeNull()->and($data['members'])->toBe([])->and($data['shifts'])->toBe([])
        ->and(collect($data['waiting'])->pluck('id')->all())->toBe([$waiting->id])
        ->and($data['kpis'])->toMatchArray(['waiting' => 1, 'longest_wait_seconds' => null, 'open' => 0, 'capacity' => 0, 'sla_pct' => null])
        ->and($data['last_call'])->toBeNull()
        ->and($data['default_roster'])->toBe(['morning' => [5]])
        ->and(collect($data['templates'])->pluck('key')->all())->toBe(['morning', 'evening'])
        ->and(collect($data['users'])->pluck('name'))->not->toContain('موقوفة');
});

it('seats what the lounge can seat and counts the rest', function () {
    Shift::factory()->create();
    QueueEntry::factory()->count(BoardState::WAITING_LIMIT + 6)->create();

    $this->actingAs(boardSupervisor())->getJson('/board/state')->assertOk()
        ->assertJsonCount(BoardState::WAITING_LIMIT, 'data.waiting')->assertJsonPath('data.kpis.waiting', BoardState::WAITING_LIMIT + 6);
});

it('reads the state with a number of queries that does not grow with the lounge or the desks', function () {
    $sup = boardSupervisor();
    $shift = Shift::factory()->create(['leader_user_id' => $sup->id]);
    $count = function () use ($sup): int {
        $this->actingAs($sup)->getJson('/board/state')->assertOk(); // warm: the session and the user are loaded
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($sup)->getJson('/board/state')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    boardWindow(boardDesk($shift), ['last_agent_message_at' => now()->subSeconds(30)]);
    boardWaiting();
    QueueDecision::create(['shift_id' => $shift->id, 'trigger' => 't', 'lines' => ['x'], 'created_at' => now()]);
    $few = $count();

    foreach (range(1, 6) as $i) {
        $desk = boardDesk($shift);
        boardWindow($desk, ['last_agent_message_at' => now()->subSeconds(30)]);
        boardWindow($desk, ['last_agent_message_at' => now()->subSeconds(30)]);
    }
    foreach (range(1, 30) as $i) {
        boardWaiting(['priority' => $i % 3 === 0 ? 'overnight' : 'live']);
        QueueDecision::create(['shift_id' => $shift->id, 'trigger' => 't', 'lines' => ['x'], 'created_at' => now()]);
    }
    $many = $count();

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(30);
});
