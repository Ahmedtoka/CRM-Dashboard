<?php

use App\Http\Resources\ConversationResource;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** A moderator on the open shift with one open window. @return array{0: User, 1: ShiftMember, 2: QueueEntry} */
function deskWithWindow(?Shift $shift = null, array $entry = []): array
{
    $shift ??= Shift::factory()->create();
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create($entry + [
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1,
        'called_at' => now(), 'delivered_at' => now(),
    ]);
    $e->conversation->update(['assignee_id' => $u->id, 'assigned_at' => now(), 'queue_entry_id' => $e->id, 'handler' => 'human']);

    return [$u, $m, $e];
}

// ───── GET /queue/me ─────

it('shows my desk, my open windows and the timers', function () {
    [$u, $m, $e] = deskWithWindow(entry: ['ticket_no' => 52, 'priority' => 'returning', 'last_agent_message_at' => now()->subSeconds(200), 'silence_warned_at' => now()->subSeconds(20)]);
    $e->conversation->update(['last_customer_message_at' => now()->subSeconds(400)]);
    [, , $other] = deskWithWindow($m->shift);

    $response = $this->actingAs($u)->getJson('/queue/me')->assertOk();

    expect(collect($response->json('data.entries'))->pluck('id')->all())->toBe([$e->id])->not->toContain($other->id);
    $response->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.member.id', $m->id)
        ->assertJsonPath('data.member.user.id', $u->id)
        ->assertJsonPath('data.member.status', 'busy')
        ->assertJsonPath('data.member.cap', 3)
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.id', $e->id)
        ->assertJsonPath('data.entries.0.ticket', 52)
        ->assertJsonPath('data.entries.0.priority', 'returning')
        ->assertJsonPath('data.entries.0.window_no', 1)
        ->assertJsonPath('data.entries.0.conversation_id', $e->conversation_id)
        ->assertJsonPath('data.entries.0.silence_left_seconds', 100)
        ->assertJsonPath('data.entries.0.silence_warned', true)
        ->assertJsonPath('data.settings.silence_warn_seconds', 180)
        ->assertJsonPath('data.settings.silence_close_seconds', 300)
        ->assertJsonPath('data.settings.windows_per_moderator', 3);
});

it('reports no silence clock while the customer wrote last or the moderator has not replied', function () {
    [$u, , $e] = deskWithWindow();

    $this->actingAs($u)->getJson('/queue/me')->assertOk()
        ->assertJsonPath('data.entries.0.silence_left_seconds', null)
        ->assertJsonPath('data.entries.0.silence_warned', false);

    $e->update(['last_agent_message_at' => now()->subSeconds(60)]);
    $e->conversation->update(['last_customer_message_at' => now()->subSeconds(10)]);

    $this->actingAs($u)->getJson('/queue/me')->assertOk()->assertJsonPath('data.entries.0.silence_left_seconds', null);
});

it('keeps a window from an earlier shift in my list and shows no desk when I am not on the open shift', function () {
    $early = Shift::factory()->create(['shift_key' => 'early', 'status' => 'closed']);
    [$u, $m, $e] = deskWithWindow($early);
    $m->update(['status' => 'left']);
    Shift::factory()->create();

    $this->actingAs($u)->getJson('/queue/me')->assertOk()
        ->assertJsonPath('data.member', null)
        ->assertJsonPath('data.entries.0.id', $e->id);
});

it('answers with an empty desk for somebody who is not on the roster', function () {
    deskWithWindow();

    $this->actingAs(User::factory()->create())->getJson('/queue/me')->assertOk()
        ->assertJsonPath('data.enabled', true)->assertJsonPath('data.member', null)->assertJsonPath('data.entries', []);
});

it('answers cheaply and empty when the queue is off', function () {
    [$u] = deskWithWindow();
    QueueSetting::current()->update(['enabled' => false]);

    DB::enableQueryLog();
    $response = $this->actingAs($u)->getJson('/queue/me')->assertOk();
    $queueQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'queue_entries') || str_contains($q['query'], 'shift'));
    DB::disableQueryLog();

    $response->assertJsonPath('data.enabled', false)->assertJsonPath('data.member', null)
        ->assertJsonPath('data.entries', [])->assertJsonPath('data.settings', null);
    expect($queueQueries)->toBeEmpty();
});

it('needs a signed-in user', function () {
    [, , $e] = deskWithWindow();

    $this->getJson('/queue/me')->assertUnauthorized();
    $this->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry'])->assertUnauthorized();
    $this->postJson("/queue/entries/{$e->id}/escalate")->assertUnauthorized();
    $this->postJson('/queue/me/status', ['status' => 'break'])->assertUnauthorized();
});

// ───── POST /queue/entries/{entry}/close ─────

it('lets the assignee close her window with a reason', function (string $reason) {
    [$u, $m, $e] = deskWithWindow();

    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", ['reason' => $reason])->assertOk()
        ->assertJsonPath('data.id', $e->id)->assertJsonPath('data.status', 'closed')->assertJsonPath('data.close_reason', $reason);

    $e->refresh();
    expect($e->status)->toBe('closed')->and($e->close_reason)->toBe($reason)->and($e->closed_by_id)->toBe($u->id)
        ->and($e->conversation->assignee_id)->toBeNull()
        ->and($m->fresh()->status)->toBe('available');
    $this->actingAs($u)->getJson('/queue/me')->assertOk()->assertJsonPath('data.entries', []);
})->with(['inquiry', 'problem']);

it('opens a support case of the chosen type on a case close', function () {
    [$u, , $e] = deskWithWindow(entry: ['bot_summary' => ['topic' => 'مقاس غلط']]);

    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'case', 'case_type' => 'return'])->assertOk()
        ->assertJsonPath('data.close_reason', 'case');

    $case = SupportCase::query()->where('queue_entry_id', $e->id)->first();
    expect($case)->not->toBeNull()->and($case->type)->toBe('return')->and($case->opened_by_id)->toBe($u->id)
        ->and($e->fresh()->support_case_id)->toBe($case->id);
    $this->actingAs($u)->postJson('/queue/me/status', ['status' => 'available'])->assertOk();
});

it('rejects a close without a reason, with a system reason or with a bad case type', function (array $body, string $field, string $message) {
    [$u, , $e] = deskWithWindow();

    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", $body)->assertUnprocessable()
        ->assertJsonValidationErrors([$field => __($message)]);

    expect($e->fresh()->status)->toBe('active')->and(SupportCase::query()->count())->toBe(0);
})->with([
    'no reason' => [[], 'reason', 'errors.queue.reason_required'],
    'empty reason' => [['reason' => ''], 'reason', 'errors.queue.reason_required'],
    'auto is the system\'s' => [['reason' => 'auto'], 'reason', 'errors.queue.reason_required'],
    'resolved elsewhere is the system\'s' => [['reason' => 'resolved_elsewhere'], 'reason', 'errors.queue.reason_required'],
    'escalation is its own endpoint' => [['reason' => 'escalation'], 'reason', 'errors.queue.reason_required'],
    'case without a type' => [['reason' => 'case'], 'case_type', 'errors.queue.case_type_required'],
    'case with an unknown type' => [['reason' => 'case', 'case_type' => 'gift'], 'case_type', 'errors.queue.case_type_required'],
]);

it('forbids another moderator to close or escalate a window that is not hers', function () {
    [, $m, $e] = deskWithWindow();
    [$other] = deskWithWindow($m->shift);

    $this->actingAs($other)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry'])->assertForbidden()
        ->assertJsonPath('message', __('errors.queue.not_your_window'));
    $this->actingAs($other)->postJson("/queue/entries/{$e->id}/escalate")->assertForbidden()
        ->assertJsonPath('message', __('errors.queue.not_your_window'));

    expect($e->fresh()->status)->toBe('active')->and($e->fresh()->assigned_user_id)->not->toBe($other->id);
});

it('lets a supervisor or an admin close any window', function (string $role) {
    [$u, , $e] = deskWithWindow();
    $boss = User::factory()->create(['role' => $role]);

    $this->actingAs($boss)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'problem'])->assertOk()
        ->assertJsonPath('data.close_reason', 'problem');

    expect($e->fresh()->closed_by_id)->toBe($boss->id)->and($e->fresh()->assigned_user_id)->toBe($u->id);
})->with(['supervisor', 'admin']);

it('answers a close of a window that is no longer open with a clean error', function (array $state) {
    [$u, , $e] = deskWithWindow();
    $e->forceFill($state)->save();

    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry'])->assertStatus(409)
        ->assertJsonPath('message', __('errors.queue.window_not_open'));

    expect($e->fresh()->close_reason)->toBe($state['close_reason']);
})->with([
    'closed by the silence timer' => [['status' => 'closed', 'close_reason' => 'auto', 'closed_at' => '2026-10-05 09:59:00']],
    'cancelled' => [['status' => 'cancelled', 'close_reason' => 'cancelled', 'closed_at' => '2026-10-05 09:59:00']],
]);

it('answers 404 for a window that does not exist', function () {
    [$u] = deskWithWindow();

    $this->actingAs($u)->postJson('/queue/entries/999999/close', ['reason' => 'inquiry'])->assertNotFound();
    $this->actingAs($u)->postJson('/queue/entries/999999/escalate')->assertNotFound();
});

// ───── POST /queue/entries/{entry}/escalate ─────

it('hands my window to the shift leader and frees it at once', function () {
    [$u, $m, $e] = deskWithWindow(entry: ['ticket_no' => 77]);

    $response = $this->actingAs($u)->postJson("/queue/entries/{$e->id}/escalate")->assertOk()
        ->assertJsonPath('data.priority', 'escalation')->assertJsonPath('data.ticket', 77)
        ->assertJsonPath('data.conversation_id', $e->conversation_id);

    $e->refresh();
    expect($e->status)->toBe('closed')->and($e->close_reason)->toBe('escalation')->and($e->closed_by_id)->toBe($u->id)
        ->and($e->ticket_no % 100000)->toBe(77)
        ->and($response->json('data.id'))->not->toBe($e->id)
        ->and($m->fresh()->status)->toBe('available');
    $this->actingAs($u)->getJson('/queue/me')->assertOk()->assertJsonPath('data.entries', []);
});

it('lets a supervisor escalate a moderator\'s window', function () {
    [, , $e] = deskWithWindow();

    $this->actingAs(User::factory()->create(['role' => 'supervisor']))->postJson("/queue/entries/{$e->id}/escalate")->assertOk()
        ->assertJsonPath('data.priority', 'escalation');
});

it('refuses to escalate a window that is already with the shift leader', function () {
    $leader = User::factory()->create(['role' => 'supervisor', 'last_seen_at' => now()]);
    $shift = Shift::factory()->create(['leader_user_id' => $leader->id]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $leader->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $leader->id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now(), 'priority' => 'escalation']);
    $e->conversation->update(['assignee_id' => $leader->id, 'queue_entry_id' => $e->id]);

    $this->actingAs($leader)->postJson("/queue/entries/{$e->id}/escalate")->assertUnprocessable()
        ->assertJsonPath('message', __('errors.queue.already_with_leader'));

    expect($e->fresh()->status)->toBe('active');
});

it('answers an escalation of a window that is no longer open with a clean error', function () {
    [$u, , $e] = deskWithWindow();
    $e->forceFill(['status' => 'closed', 'close_reason' => 'auto', 'closed_at' => now()])->save();

    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/escalate")->assertStatus(409)
        ->assertJsonPath('message', __('errors.queue.window_not_open'));

    expect(QueueEntry::query()->where('priority', 'escalation')->count())->toBe(0);
});

// ───── POST /queue/me/status ─────

it('starts my break at once when I have no open window and ends it on available', function () {
    $shift = Shift::factory()->create();
    $u = User::factory()->create(['last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id]);

    $this->actingAs($u)->postJson('/queue/me/status', ['status' => 'break'])->assertOk()
        ->assertJsonPath('data.status', 'break')->assertJsonPath('data.id', $m->id)
        ->assertJsonPath('data.break_ends_at', now()->addMinutes(30)->toIso8601String());

    $this->actingAs($u)->postJson('/queue/me/status', ['status' => 'available'])->assertOk()
        ->assertJsonPath('data.status', 'available')->assertJsonPath('data.break_ends_at', null);
});

it('keeps my break pending while I still have an open window', function () {
    [$u, $m] = deskWithWindow();

    $this->actingAs($u)->postJson('/queue/me/status', ['status' => 'break'])->assertOk()->assertJsonPath('data.status', 'pending_break');
    expect($m->fresh()->status)->toBe('pending_break');

    $this->actingAs($u)->postJson('/queue/me/status', ['status' => 'available'])->assertOk()->assertJsonPath('data.status', 'busy');
});

it('rejects a status I may not set myself', function (array $body) {
    [$u, $m] = deskWithWindow();

    $this->actingAs($u)->postJson('/queue/me/status', $body)->assertUnprocessable()->assertJsonValidationErrors(['status']);

    expect($m->fresh()->status)->toBe('busy');
})->with([
    'nothing' => [[]],
    'offline' => [['status' => 'offline']],
    'left' => [['status' => 'left']],
    'busy' => [['status' => 'busy']],
]);

it('answers a status change of somebody who is not on the open shift with a clean error', function () {
    Shift::factory()->create();

    $this->actingAs(User::factory()->create())->postJson('/queue/me/status', ['status' => 'break'])->assertNotFound()
        ->assertJsonPath('message', __('errors.queue.not_on_shift'));
});

it('changes only my own desk', function () {
    [$u, $m] = deskWithWindow();
    [, $other] = deskWithWindow($m->shift);

    $this->actingAs($u)->postJson('/queue/me/status', ['status' => 'break'])->assertOk();

    expect($other->fresh()->status)->toBe('busy');
});

// ───── queue disabled ─────

it('refuses every action while the queue is off', function () {
    [$u, $m, $e] = deskWithWindow();
    QueueSetting::current()->update(['enabled' => false]);

    foreach ([
        ["/queue/entries/{$e->id}/close", ['reason' => 'inquiry']],
        ["/queue/entries/{$e->id}/escalate", []],
        ['/queue/me/status', ['status' => 'break']],
    ] as [$url, $body]) {
        $this->actingAs($u)->postJson($url, $body)->assertStatus(409)->assertJsonPath('message', __('errors.queue.disabled'));
    }

    expect($e->fresh()->status)->toBe('active')->and($m->fresh()->status)->toBe('busy');
});

// ───── translations and the conversation resource ─────

it('answers in the user\'s language', function () {
    [, $m, $e] = deskWithWindow();
    [$other] = deskWithWindow($m->shift);
    $other->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($other)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry'])->assertForbidden()
        ->assertJsonPath('message', 'الشباك ده مش بتاعك.');
});

it('gives the inbox what it needs to act on the window of a conversation', function () {
    [$u, , $e] = deskWithWindow(entry: ['priority' => 'returning']);

    $data = (new ConversationResource($e->conversation->fresh()))->resolve(request());

    expect($data['queue_entry'])->toMatchArray(['id' => $e->id, 'assigned_user_id' => $u->id, 'priority' => 'returning', 'window_no' => 1]);
});

// ───── the old «حل» path and somebody else's window ─────

it('does not let another moderator resolve somebody else\'s open window through the inbox', function () {
    [, $m, $e] = deskWithWindow();
    [$other] = deskWithWindow($m->shift);
    $other->userPlatforms()->create(['platform' => $e->conversation->platform->value]);
    $other->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($other)->postJson("/inbox/conversations/{$e->conversation_id}/resolve")->assertForbidden()
        ->assertJsonPath('message', 'الشباك ده مش بتاعك.');

    expect($e->fresh()->status)->toBe('active')->and($e->fresh()->close_reason)->toBeNull()
        ->and($e->conversation->fresh()->status->value)->toBe('open');
});

it('lets the assignee resolve her own window through the inbox', function () {
    [$u, , $e] = deskWithWindow();
    $u->userPlatforms()->create(['platform' => $e->conversation->platform->value]);

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/resolve")->assertOk();

    expect($e->fresh()->close_reason)->toBe('resolved_elsewhere')->and($e->conversation->fresh()->status->value)->toBe('resolved');
});

it('lets a supervisor or an admin resolve a moderator\'s window through the inbox', function (string $role) {
    [, , $e] = deskWithWindow();

    $this->actingAs(User::factory()->create(['role' => $role]))->postJson("/inbox/conversations/{$e->conversation_id}/resolve")->assertOk();

    expect($e->fresh()->status)->toBe('closed')->and($e->fresh()->close_reason)->toBe('resolved_elsewhere');
})->with(['supervisor', 'admin']);

it('lets any moderator resolve a customer who is still waiting in the lounge', function () {
    $e = QueueEntry::factory()->create();
    $u = User::factory()->create(['role' => 'moderator']);
    $u->userPlatforms()->create(['platform' => $e->conversation->platform->value]);

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/resolve")->assertOk();

    expect($e->fresh()->status)->toBe('cancelled');
});

it('leaves the old resolve as it was while the queue is off', function () {
    [, $m, $e] = deskWithWindow();
    [$other] = deskWithWindow($m->shift);
    $other->userPlatforms()->create(['platform' => $e->conversation->platform->value]);
    QueueSetting::current()->update(['enabled' => false]);

    $this->actingAs($other)->postJson("/inbox/conversations/{$e->conversation_id}/resolve")->assertOk();

    expect($e->conversation->fresh()->status->value)->toBe('resolved');
});

// ───── review I1: «رجوع للبوت» on somebody else's window ─────

it('does not let another moderator return somebody else\'s open window to the bot', function () {
    [, $m, $e] = deskWithWindow();
    [$other] = deskWithWindow($m->shift);
    $other->userPlatforms()->create(['platform' => $e->conversation->platform->value]);
    $other->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($other)->postJson("/inbox/conversations/{$e->conversation_id}/return-to-bot")->assertForbidden()
        ->assertJsonPath('message', 'الشباك ده مش بتاعك.');

    expect($e->fresh()->status)->toBe('active')->and($e->fresh()->close_reason)->toBeNull()
        ->and($e->conversation->fresh()->handler->value)->toBe('human');
});

it('lets the assignee, a supervisor or an admin return an open window to the bot', function (string $who) {
    [$u, , $e] = deskWithWindow();
    $u->userPlatforms()->create(['platform' => $e->conversation->platform->value]);
    $actor = $who === 'assignee' ? $u : User::factory()->create(['role' => $who]);

    $this->actingAs($actor)->postJson("/inbox/conversations/{$e->conversation_id}/return-to-bot")->assertOk();

    expect($e->fresh()->status)->toBe('closed')->and($e->fresh()->close_reason)->toBe('cancelled')
        ->and($e->conversation->fresh()->handler->value)->toBe('bot');
})->with(['assignee', 'supervisor', 'admin']);

it('lets any moderator return a customer still waiting in the lounge to the bot', function () {
    $e = QueueEntry::factory()->create();
    $u = User::factory()->create(['role' => 'moderator']);
    $u->userPlatforms()->create(['platform' => $e->conversation->platform->value]);

    $this->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/return-to-bot")->assertOk();

    expect($e->fresh()->status)->toBe('cancelled')->and($e->fresh()->closed_by_id)->toBe($u->id);
});

it('leaves return-to-bot as it was while the queue is off', function () {
    [, $m, $e] = deskWithWindow();
    [$other] = deskWithWindow($m->shift);
    $other->userPlatforms()->create(['platform' => $e->conversation->platform->value]);
    QueueSetting::current()->update(['enabled' => false]);

    $this->actingAs($other)->postJson("/inbox/conversations/{$e->conversation_id}/return-to-bot")->assertOk();

    expect($e->conversation->fresh()->handler->value)->toBe('bot');
});

it('applies the same rule to the API (mobile) resolve and return-to-bot', function () {
    [, $m, $e] = deskWithWindow();
    [$other] = deskWithWindow($m->shift);
    $other->userPlatforms()->create(['platform' => $e->conversation->platform->value]);
    Sanctum::actingAs($other);

    $this->postJson("/api/v1/conversations/{$e->conversation_id}/resolve")->assertForbidden();
    $this->postJson("/api/v1/conversations/{$e->conversation_id}/return-to-bot")->assertForbidden();

    expect($e->fresh()->status)->toBe('active');
});

// ───── minors: the leader in /queue/me, rate limit ─────

it('tells the inbox who leads the open shift', function () {
    [$u, $m] = deskWithWindow();

    $this->actingAs($u)->getJson('/queue/me')->assertOk()->assertJsonPath('data.leader_user_id', $m->shift->leader_user_id);
});

it('rate limits the queue endpoints per user', function () {
    [$u] = deskWithWindow();

    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($u)->getJson('/queue/me')->assertOk();
    }

    $this->actingAs($u)->getJson('/queue/me')->assertStatus(429);
    $this->actingAs(User::factory()->create(['role' => 'moderator']))->getJson('/queue/me')->assertOk();
});
