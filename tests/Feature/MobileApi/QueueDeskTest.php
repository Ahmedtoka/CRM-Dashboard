<?php

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Jobs\SendClosingMessage;
use App\Queue\Jobs\SendQueueMessage;
use App\Queue\ShiftService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;

/*
 * The moderator's queue desk on the mobile API (/api/v1/queue/*): the same trait and JSON as the
 * web `/queue/*` endpoints (tests/Feature/Queue/WindowEndpointsTest.php covers those in depth).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** A moderator allowed on every platform, seen now. */
function mqModerator(array $attrs = []): User
{
    $u = User::factory()->create($attrs + ['role' => 'moderator', 'last_seen_at' => now()]);

    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $platform) {
        $u->userPlatforms()->create(['platform' => $platform]);
    }

    return $u;
}

/** A moderator on the open shift with one open window. @return array{0: User, 1: ShiftMember, 2: QueueEntry} */
function mqDesk(?Shift $shift = null, array $entry = []): array
{
    $shift ??= Shift::query()->where('status', 'open')->first() ?? Shift::factory()->create();
    $u = mqModerator();
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create($entry + [
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1,
        'called_at' => now(), 'delivered_at' => now(),
    ]);
    $e->conversation->update(['assignee_id' => $u->id, 'assigned_at' => now(), 'queue_entry_id' => $e->id, 'handler' => 'human']);

    return [$u, $m, $e];
}

// ───── GET queue/me ─────

it('answers the queue-off payload', function () {
    QueueSetting::current()->update(['enabled' => false]);
    Sanctum::actingAs(mqModerator());

    $this->getJson('/api/v1/queue/me')->assertOk()
        ->assertJsonPath('data.enabled', false)->assertJsonPath('data.member', null)->assertJsonPath('data.entries', [])
        ->assertJsonPath('data.settings', null)->assertJsonPath('data.leader_user_id', null)->assertJsonPath('data.attendance', null)
        ->assertJsonStructure(['data' => ['server_time']]);
});

it('shows the same desk as the web', function () {
    [$u, $m, $e] = mqDesk(entry: ['ticket_no' => 52]);

    $web = $this->actingAs($u)->getJson('/queue/me')->assertOk()->json('data');
    Sanctum::actingAs($u);
    $api = $this->getJson('/api/v1/queue/me')->assertOk()
        ->assertJsonPath('data.member.id', $m->id)
        ->assertJsonPath('data.entries.0.id', $e->id)
        ->assertJsonPath('data.entries.0.ticket', 52)
        ->assertJsonPath('data.settings.windows_per_moderator', 3)
        ->json('data');

    unset($web['server_time'], $api['server_time']);
    expect($api)->toBe($web);
});

// ───── attendance ─────

it('checks her in within the hours and refuses outside them with the start time', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 08:00', 'Africa/Cairo'));
    Sanctum::actingAs($u = mqModerator(['locale' => 'ar']));

    $this->postJson('/api/v1/queue/me/check-in')->assertStatus(409)
        ->assertJsonPath('message', 'مفيش شيفت شغال دلوقتي. الشيفت بيبدأ 10:00.');
    expect(ShiftMember::count())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    Shift::factory()->create();

    $this->postJson('/api/v1/queue/me/check-in')->assertOk()
        ->assertJsonPath('data.member.user.id', $u->id)
        ->assertJsonPath('data.attendance.shift_open', true);
});

it('refuses a check-in to a moderator without a platform', function () {
    Shift::factory()->create();
    Sanctum::actingAs(User::factory()->create(['role' => 'moderator', 'locale' => 'ar', 'last_seen_at' => now()]));

    $this->postJson('/api/v1/queue/me/check-in')->assertForbidden()
        ->assertJsonPath('message', 'حسابك مش مسموح له بأي منصة، كلّمي المشرفة.');
});

it('puts her break on hold while a window is open', function () {
    [$u, $m] = mqDesk();
    Sanctum::actingAs($u);

    $this->postJson('/api/v1/queue/me/status', ['status' => 'break'])->assertOk()
        ->assertJsonPath('data.id', $m->id)->assertJsonPath('data.status', 'pending_break');
    expect($m->fresh()->status)->toBe('pending_break');

    $this->postJson('/api/v1/queue/me/status', ['status' => 'offline'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
});

it('checks her out into checking_out while she holds windows, then hands them back', function () {
    [$u, $m, $e] = mqDesk();
    Sanctum::actingAs($u);

    $this->postJson('/api/v1/queue/me/hand-back')->assertStatus(409)->assertJsonPath('message', __('errors.queue.not_checking_out'));

    $this->postJson('/api/v1/queue/me/check-out')->assertOk()
        ->assertJsonPath('data.member.status', 'checking_out')->assertJsonPath('data.entries.0.id', $e->id);

    $this->postJson('/api/v1/queue/me/hand-back')->assertOk()
        ->assertJsonPath('data.member', null)->assertJsonPath('data.entries', []);
    expect($m->fresh()->status)->toBe('left');
});

it('answers a check-out off the shift with 404', function () {
    Shift::factory()->create();
    Sanctum::actingAs(mqModerator());

    $this->postJson('/api/v1/queue/me/check-out')->assertNotFound()->assertJsonPath('message', __('errors.queue.not_on_shift'));
});

// ───── close ─────

it('closes an inquiry with an outcome and sends the closing message', function () {
    Bus::fake([SendClosingMessage::class]);
    [$u, $m, $e] = mqDesk();
    Sanctum::actingAs($u);

    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'browsing'])->assertOk()
        ->assertJsonPath('data.id', $e->id)->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.close_reason', 'inquiry')->assertJsonPath('data.support_case_id', null);

    expect($e->fresh()->status)->toBe('closed')->and($m->fresh()->status)->toBe('available');
    Bus::assertDispatched(SendClosingMessage::class, fn ($job) => $job->entryId === $e->id && $job->withThanks); // close cycle Q1
});

it('needs a case type on a case close and opens the support case', function () {
    [$u, , $e] = mqDesk();
    Sanctum::actingAs($u);

    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'case', 'outcome' => 'browsing'])->assertUnprocessable()
        ->assertJsonValidationErrors(['case_type' => __('errors.queue.case_type_required')]);
    expect(SupportCase::count())->toBe(0);

    $response = $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'case', 'case_type' => 'return', 'outcome' => 'browsing'])->assertOk()
        ->assertJsonPath('data.close_reason', 'case');

    $case = SupportCase::query()->where('queue_entry_id', $e->id)->firstOrFail();
    expect($case->type)->toBe('return')->and($response->json('data.support_case_id'))->toBe($case->id);
});

it('needs an outcome unless the chat has an automatic one', function () {
    [$u, , $e] = mqDesk();
    Sanctum::actingAs($u);

    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'inquiry'])->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome' => __('errors.outcome.required')]);
    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'other'])->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome_note']);

    // An order in this episode is the automatic outcome: the moderator does not pick one.
    $conversation = $e->conversation;
    Order::factory()->create(['conversation_id' => $conversation->id, 'customer_id' => $conversation->customer_id, 'status' => 'confirmed']);

    $this->getJson("/api/v1/conversations/{$conversation->id}/context")->assertOk()->assertJsonPath('data.outcome.auto', 'ordered');
    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'inquiry'])->assertOk()->assertJsonPath('data.status', 'closed');
});

it('forbids closing or escalating another moderator\'s window', function () {
    [, $m, $e] = mqDesk();
    [$other] = mqDesk($m->shift);
    $other->forceFill(['locale' => 'ar'])->save();
    Sanctum::actingAs($other);

    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'browsing'])->assertForbidden()
        ->assertJsonPath('message', 'الشباك ده مش بتاعك.');
    $this->postJson("/api/v1/queue/entries/{$e->id}/escalate")->assertForbidden();

    expect($e->fresh()->status)->toBe('active');
});

it('lets a supervisor close any window', function () {
    [, , $e] = mqDesk();
    Sanctum::actingAs($boss = User::factory()->create(['role' => 'supervisor']));

    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'problem', 'outcome' => 'browsing'])->assertOk()
        ->assertJsonPath('data.close_reason', 'problem');
    expect($e->fresh()->closed_by_id)->toBe($boss->id);
});

it('answers 409 window_not_open for a window that was already closed', function () {
    [$u, , $e] = mqDesk();
    $e->forceFill(['status' => 'closed', 'close_reason' => 'auto', 'closed_at' => now()])->save();
    Sanctum::actingAs($u);

    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'browsing'])->assertStatus(409)
        ->assertJsonPath('message', __('errors.queue.window_not_open'));
    $this->postJson("/api/v1/queue/entries/{$e->id}/escalate")->assertStatus(409)
        ->assertJsonPath('message', __('errors.queue.window_not_open'));
});

// ───── escalate ─────

it('escalates to the leader once, then refuses with already_with_leader', function () {
    $leader = mqModerator(['role' => 'supervisor']);
    $shift = Shift::factory()->create(['leader_user_id' => $leader->id]);
    ShiftMember::factory()->for($shift)->create(['user_id' => $leader->id, 'status' => 'available']);
    [$u, , $e] = mqDesk($shift);
    Sanctum::actingAs($u);

    $new = $this->postJson("/api/v1/queue/entries/{$e->id}/escalate")->assertOk()
        ->assertJsonPath('data.priority', 'escalation')->json('data');

    $escalated = QueueEntry::query()->findOrFail($new['id']);
    expect($escalated->assigned_user_id)->toBe($leader->id);

    Sanctum::actingAs($leader);
    $this->postJson("/api/v1/queue/entries/{$escalated->id}/escalate")->assertUnprocessable()
        ->assertJsonPath('message', __('errors.queue.already_with_leader'));
});

// ───── Review Focus 1: the app in the background keeps her online ─────

it('keeps a moderator who keeps sending the mobile heartbeat online through tickMembers', function () {
    Shift::factory()->create();
    $u = mqModerator();
    $m = app(ShiftService::class)->checkIn($u);
    $token = $u->createToken('pixel-8')->plainTextToken;
    $quiet = mqModerator();
    $mq = app(ShiftService::class)->checkIn($quiet);
    $statusBefore = $m->fresh()->status;

    // Six minutes with the app in the background: a heartbeat every 45 s, the scheduler ticking every minute.
    for ($s = 45; $s <= 360; $s += 45) {
        Carbon::setTestNow(now()->addSeconds(45));
        $this->withToken($token)->postJson('/api/v1/heartbeat')->assertOk();
        app('auth')->forgetGuards();
        app(ShiftService::class)->tickMembers();
    }

    expect($m->fresh()->status)->toBe($statusBefore)->not->toBe('offline')->not->toBe('left')
        ->and($u->fresh()->last_seen_at->equalTo(now()))->toBeTrue()
        // The control: no heartbeat for six minutes is offline (or past it).
        ->and($mq->fresh()->status)->toBeIn(['offline', 'left']);
});

// ───── who may use it ─────

it('needs a token, an active user and an inbox role', function () {
    [$u, , $e] = mqDesk();

    $this->getJson('/api/v1/queue/me')->assertUnauthorized();
    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'inquiry'])->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::MediaBuyer]));
    $this->getJson('/api/v1/queue/me')->assertForbidden();
    app('auth')->forgetGuards();

    $u->forceFill(['is_active' => false])->save();
    $token = $u->createToken('phone')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/queue/me')->assertUnauthorized();
});

it('keeps the queue routes named as in the contract', function () {
    expect(route('api.v1.queue.me', absolute: false))->toBe('/api/v1/queue/me')
        ->and(route('api.v1.queue.entries.close', 7, false))->toBe('/api/v1/queue/entries/7/close');
});
